<?php

/**
 * WHM Backup Solutions
 * https://whmbackup.solutions
 * 
 * Description:     This script utilises cPanel's official API's to enable reseller
 *                  users to automate backups of accounts within their reseller account,
 *                  a feature currently missing.
 * 
 * Requirements:    cPanel Version 11.68+
 *                  PHP Version 5.6+
 * 
 * Instructions:    For instructions on how to configure and run this script see README.txt
 *                  or visit https://whmbackup.solutions/documentation/
 *  
 * LICENSE: This source file is subject to GNU GPL-3.0-or-later
 * that is available through the world-wide-web at the following URI:
 * https://www.gnu.org/licenses/gpl.html.  If you did not receive a copy of
 * the GNU GPL License and are unable to obtain it through the web, please
 * send a note to peter@whmbackup.solutions so we can mail you a copy immediately.
 *
 * @author      Peter Kelly <peter@whmbackup.solutions>
 * @license     https://www.gnu.org/licenses/gpl.html GNU GPL 3.0 or later
 * @link        https://whmbackup.solutions
 * @filename    ftp_retention.php
 */

// Set Log File Name
$log_file = "ftpretention-" . date("YmdHis", time()) . ".log";

// Get Current Directory
$directory = realpath(__dir__ ) . DIRECTORY_SEPARATOR;

// Include Functions file.
include ($directory . "resources" . DIRECTORY_SEPARATOR . "functions.php");

$config_name = NULL;

if (array_key_exists("config", $_GET))
	$config_name = $_GET["config"];

$include_config = include_config($config_name);
if ($include_config["error"])
    record_log("system", $include_config["response"], true);
$config = $include_config["response"];

	// Valid Config Variables
	$config_variables = array(
		"date_format",
		"timezone",
		"obfuscate_config",
		"check_version",
		"type_of_backup",
		"backup_destination",
		"backup_hostname",
		"backup_port",
		"backup_user",
		"backup_pass",
		"backup_email",
		"backup_rdir",
		"max_backups_per_account",
		);

// Check Config For All Required Variables.
foreach ($config_variables as $var)
{
	if (!isset($config[$var]))
		record_log("system", "Variable &#36;config[&#34;" . $var .
			"&#34;] Missing From Config. Please Generate A New Configuration File Using config.php.new", true);
}

// Ensure Maximum Backups To Retain Is Greater Than 0.
if ($config["max_backups_per_account"] < 1)
	record_log("system", "&#36;config[&#34;max_backups_per_account&#34;] must be set to greater than 0.", true);

// Ensure Backup Destination is set to FTP.
if (($config["backup_destination"] != "ftp") && ($config["backup_destination"] != "passiveftp"))
	record_log("system",
		"You can only view/remove backups hosted on an FTP (ftp or passiveftp) server. Config file is set to " . $config["backup_destination"] .
		".", true);

// Empty Backups Array
$backups = array();

try
{
	// Connect to FTP Server
	if (!$conn_id = ftp_connect($config['backup_hostname'], $config['backup_port'], 20))
		record_log("system", "Unable to connect to FTP Server.", true);

	// Login to FTP Server
	if (!$login_result = ftp_login($conn_id, $config['backup_user'], $config['backup_pass']))
		record_log("system", "Unable to login to FTP Server.", true);

	// Enable Passive Mode?
	if (($config["backup_destination"] == "passiveftp") && (!$passive_mode = ftp_pasv($conn_id, true)))
		record_log("system", "Unable to make a connection to the FTP server using Passive Mode.", true);

    $remote_directory = $config['backup_rdir'];   
    
    if(!empty($config['ftp_retention_rdir']))
    $remote_directory = $config['ftp_retention_rdir'];

    $backups = ftp_find_backup($conn_id , $remote_directory);
    if($backups["error"] == "1")
        record_log("system", $backups["response"], true);
        
    $backups = $backups["response"];

	// Retrieve Directory Listing
//	if (!$contents = ftp_nlist($conn_id, $config['backup_rdir']))
//		record_log("system", "Unable to retrieve file listing from FTP Server.", true);

	// Loop Through FTP Directory Listing
	// e.g. $list_key => $list_file_name (e.g. 0 => backup-month.day.year_hour-minute-second_username.tar.gz)
//	foreach ($contents as $list_key => $list_file_name)
//	{
//        if(ftp_is_dir($conn_id, $list_file_name)){
//            
//        }
//  }

	// Sort $backups Array Alphanumerically
	ksort($backups);

	// Loop Through Accounts For Which Backups Exist
	foreach ($backups as $account => $bkey)
	{
		$record_log_message = "";
		// Count Number of Backups For Specific Account.
		$total_backups = count($backups[$account]);

		// Sort Backups By Date, Oldest First
		ksort($bkey);

		// Calculate The Number of Backups to Remove.
		$backups_to_remove = $total_backups - $config['max_backups_per_account'];

		// Check If Total Backups For Individual Account Is Greater Than 0.
		if ($total_backups == 0)
		{
			$record_log_message .= $account . "has no valid named backups stored on the FTP server.";
		} else
		{
			$record_log_message .= $account . " has the following " . $total_backups .
				" valid named backup(s) stored on the FTP server:";

			// Check How Many Backups For Individual Account Need Removing.
			if ($backups_to_remove > 0)
			{
				$record_log_message .= "\r\nThe " . $backups_to_remove . " oldest backup(s) for " . $account .
					" will be removed.";

				// Loop Through Each Backup
				foreach ($bkey as $backup_timestamp => $backup_file)
				{

					// Remove x Number of Oldest Backups For Account
					if ($backups_to_remove > 0)
					{
						$backups_to_remove = $backups_to_remove - 1;
						// Remove Backup From Array.
						unset($bkey[$backup_timestamp]);
						// Delete Backup From FTP Server.
						if (!ftp_delete($conn_id, $backup_file))
						{
							$record_log_message .= "\r\nUnable To Remove " . $backup_file .
								" From FTP Server.";
						} else
						{
							$record_log_message .= "\r\n- " . $backup_file .
								" has been removed.";
						}

					} else
					{
                        // No More Backups To Remove. Skip.
						break;
					}
				}
			} else
			{
				$record_log_message .= "\r\nNo backups need removing for this account.";
			}
		}
        // Record Individual Account Log
		record_log("note", $record_log_message);
	}
    
    // Issue Log If backup_email Is Set
	if (!empty($config['backup_email']))
	{
		$email_log = email_log("Backup Retention Log (WHM Backup Solutions)",
			"The FTP backup retention script has been run on " . $config['backup_hostname'] . ":" . $config['backup_port'] .
			$remote_directory . ". The log is available below.\r\n");
		if ($email_log["error"] == "0")
		{
			record_log("note", "Log File Successfully Emailed To " . $config["backup_email"]);
		} else
		{
			record_log("note", $email_log["response"], true);
		}
	} else
	{
		record_log("note", "Log File Completed.");
	}
}
catch (exception $e)
{
	record_log("system", "FTP Retention Error: " . $e->getMessage(), true);
}

?>