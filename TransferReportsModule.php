<?php
namespace TransferReports\TransferReportsModule;

use ExternalModules\AbstractExternalModule;
use ExternalModules\ExternalModules;
use Project; // Add this line if Project is in the global namespace
use Exception; // Add this line if Exception is in the global namespace

class TransferReportsModule extends AbstractExternalModule

{

function sftpmethod($cronAttributes) {
        $today = new \DateTime();
        $current_hour = $today->format('G');
        $current_day = $today->format('N'); // 0-6 Sunday to Saturday

        // Log current time and day
        error_log("Current hour: $current_hour, Current day: $current_day");

        $framework = \ExternalModules\ExternalModules::getFrameworkInstance($this->PREFIX);
        $projects = $framework->getProjectsWithModuleEnabled();

        if (count($projects) > 0) {
            foreach ($projects as $project_id) {
                try {
                    $Proj = new Project($project_id);
                    
                    // Check if the cron feature is enabled for this project
                    $cron_disable = $this->getProjectSetting('cron_disable', $project_id); 
                    if (!$cron_disable) {
                        // Get project-specific settings
                        $time_of_day = $this->getProjectSetting('time_of_day', $project_id);
                        $time_of_day2 = $this->getProjectSetting('time_of_day2', $project_id); // New setting for second run time
                        $run_on_monday = $this->getProjectSetting('run_on_monday', $project_id);
                        $run_on_tuesday = $this->getProjectSetting('run_on_tuesday', $project_id);
                        $run_on_wednesday = $this->getProjectSetting('run_on_wednesday', $project_id);
                        $run_on_thursday = $this->getProjectSetting('run_on_thursday', $project_id);
                        $run_on_friday = $this->getProjectSetting('run_on_friday', $project_id);
                        $run_on_saturday = $this->getProjectSetting('run_on_saturday', $project_id);
                        $run_on_sunday = $this->getProjectSetting('run_on_sunday', $project_id);
    
                        // Log project settings
                        error_log("Project ID: $project_id, time_of_day: $time_of_day, second_time_of_day: $time_of_day2, run_on_monday: $run_on_monday, run_on_tuesday: $run_on_tuesday, run_on_wednesday: $run_on_wednesday, run_on_thursday: $run_on_thursday, run_on_friday: $run_on_friday, run_on_saturday: $run_on_saturday, run_on_sunday: $run_on_sunday");
    
                        // Check if the current time matches the project settings
                        if (($current_hour == $time_of_day || $current_hour == $time_of_day2) && (
                            ($current_day == 1 && $run_on_monday) ||
                            ($current_day == 2 && $run_on_tuesday) ||
                            ($current_day == 3 && $run_on_wednesday) ||
                            ($current_day == 4 && $run_on_thursday) ||
                            ($current_day == 5 && $run_on_friday) ||
                            ($current_day == 6 && $run_on_saturday) ||
                            ($current_day == 0 && $run_on_sunday)
                        )) {
                            $module_cron_url = \ExternalModules\ExternalModules::getUrl($this->PREFIX, 'reportftp_now.php', $Proj->project_id, true, true);

                            $ch = curl_init();
                            curl_setopt($ch, CURLOPT_URL, $module_cron_url);
                            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
                            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                            curl_setopt($ch, CURLOPT_VERBOSE, 0);
                            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                            curl_setopt($ch, CURLOPT_AUTOREFERER, true);
                            curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
                            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
                            curl_setopt($ch, CURLOPT_FRESH_CONNECT, 1);
                            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
                            $output = curl_exec($ch);
                            curl_close($ch);

                            // Log successful execution
                            error_log("SFTP transfer triggered for project ID: $project_id");
                        }
                    }
                } catch (Exception $ee) {
                    // Handle exception for individual project
                    error_log("Error in project $project_id: " . $ee->getMessage());
                }
            }
        }
    }
}