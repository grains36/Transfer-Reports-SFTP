<?php


namespace TransferReports\TransferReportsModule;

require_once dirname(__FILE__) . '/vendor/autoload.php';

use phpseclib3\Net\SFTP;
use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use REDCap;
use Project;

class TransferReports extends AbstractExternalModule {

    public function __construct() {
        parent::__construct();
    }

  public function sendReportViaSFTP($filePath, $sftpSite, $sftpPort, $sftpUsername, $sftpPassword) {
      $sftp = new SFTP($sftpSite, $sftpPort);
      try {
          if (!$sftp->login($sftpUsername, $sftpPassword)) {
              error_log("SFTP login failed for site: $sftpSite, port: $sftpPort, username: $sftpUsername");
              // Cleanup process if login fails
              $this->cleanupCsvFiles(dirname($filePath));
              throw new \Exception('Login failed');
          }
  
          $remotePath = basename($filePath); // Use the file name as the remote path
  
          if (!$sftp->put($remotePath, file_get_contents($filePath))) {
              error_log("File upload failed for file: $filePath to remote path: $remotePath");
              // Cleanup process if file upload fails
              $this->cleanupCsvFiles(dirname($filePath));
              throw new \Exception('File upload failed');
          }
  
          // Delete the file after successful upload
          if (file_exists($filePath)) {
              error_log("Deleting CSV file after successful SFTP upload: $filePath");
              unlink($filePath);
          }
      } catch (Exception $e) {
          error_log("SFTP error: " . $e->getMessage());
          if (file_exists($filePath)) {
              error_log("Deleting CSV file due to SFTP error: $filePath");
              unlink($filePath);
          }
          // Cleanup process in case of any other errors
          $this->cleanupCsvFiles(dirname($filePath));
          throw $e; // Re-throw the exception to be handled in the calling method
      }
}
    
    private function cleanupCsvFiles($tempDir) {
        error_log("Entering cleanupCsvFiles function.");
        $files = glob($tempDir . '/*.csv'); // Use glob to find all CSV files
        foreach ($files as $file) {
            if (file_exists($file)) {
                if (is_writable($file)) {
                    error_log("Deleting writable CSV file: $file");
                    unlink($file);
                } else {
                    error_log("CSV file is not writable: $file");
                }
            }
        }
     }
  }


require_once ExternalModules::getProjectHeaderPath();

$ftpvars = new TransferReports();
$settings = ExternalModules::getProjectSettingsAsArray($ftpvars->PREFIX, $_GET['pid']);
$reports = $settings['report_id']['value'];
$reportCount = count($reports);

$multiCurl = curl_multi_init();
$curlHandles = [];

// Dynamically determine the temp directory
$moduleDir = __DIR__;
$redcapDir = dirname(dirname($moduleDir)); // Go back two directories
$tempDir = $redcapDir . DIRECTORY_SEPARATOR . 'temp';



// Specify the log file path
$logFilePath = $tempDir . DIRECTORY_SEPARATOR . 'transfer_reports.log';

for ($i = 0; $i < $reportCount; ++$i) {
    $ftptokens = $settings['token1']['value'];
    $site = $settings['site']['value'];
    $port = $settings['port']['value'];
    $username = $settings['username']['value'];
    $password = $settings['password']['value'];
    $csvFileName = $settings['csv_file_name']['value'];

    if (empty($site) || empty($port) || empty($username) || empty($password) || empty($csvFileName)) {
        continue;
    }

    $data = array(
        'token' => $ftptokens,
        'content' => 'report',
        'format' => 'csv',
        'report_id' => $reports[$i],
        'rawOrLabel' => 'raw',
        'rawOrLabelHeaders' => 'raw',
        'exportCheckboxLabel' => 'false',
        'returnFormat' => 'csv'
    );

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, APP_PATH_WEBROOT_FULL . 'api/');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_VERBOSE, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_AUTOREFERER, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
    curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data, '', '&'));

    $curlHandles[$i] = $ch;
    curl_multi_add_handle($multiCurl, $ch);
}

$running = null;
do {
    curl_multi_exec($multiCurl, $running);
    curl_multi_select($multiCurl);
} while ($running > 0);

// Close the handles
foreach ($curlHandles as $ch) {
    $result = curl_multi_getcontent($ch);
    curl_multi_remove_handle($multiCurl, $ch);
    curl_close($ch);

    $today = new \DateTime();
    $dateTime = $today->format('Ymd_His');
    $csvFileNameWithDate = "{$csvFileName}_{$dateTime}.csv";
    
    // Get the filename format setting for the project
        $filename_format = $settings['filename_format']['value'];
    
        // Determine the final CSV file name based on the selected format
        $today = new \DateTime();
        $date = $today->format('Ymd');
        $dateTime = $today->format('Ymd_His');
    
        switch ($filename_format) {
            case '1':
                $csvFileNameWithDate = "{$csvFileName}_{$date}.csv";
                break;
            case '2':
                $csvFileNameWithDate = "{$csvFileName}_{$dateTime}.csv";
                break;
            case '0':
            default:
                $csvFileNameWithDate = "{$csvFileName}.csv";
                break;
        }
    

    // Use the determined temp directory for file path
    $csvFilePath = $tempDir . DIRECTORY_SEPARATOR . $csvFileNameWithDate;

    // Log file creation path
    error_log("Creating CSV file at path: $csvFilePath", 3, $logFilePath);

    if (file_put_contents($csvFilePath, $result) === false) {
        // Handle file write failure
        error_log("Failed to write CSV file: $csvFilePath", 3, $logFilePath);
    }

    // Check if the file is created and not empty
    if (file_exists($csvFilePath) && filesize($csvFilePath) > 0) {
        // Establish SFTP connection
        try {
            $ftpvars->sendReportViaSFTP($csvFilePath, $site, $port, $username, $password);
        } catch (Exception $e) {
            // Handle SFTP upload failure
            error_log("SFTP upload failed: " . $e->getMessage(), 3, $logFilePath);
        }

        if (file_exists($csvFilePath)) {
            error_log("Deleting CSV file: $csvFilePath", 3, $logFilePath);
            unlink($csvFilePath);
        }
    } else {
        // Handle CSV file creation failure
        error_log("CSV file creation failed or file is empty: $csvFilePath", 3, $logFilePath);
    }
}


   curl_multi_close($multiCurl);


   ?>