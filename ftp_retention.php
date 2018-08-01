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

// Check Existance of Config and Include.
if (file_exists($directory . "config.php")) {
    include ($directory . "config.php");

    // Check If Config Needs Obsfuscating
    if ($config["obfuscate_config"] == true) {
        // Obfuscate $config Array
        $obfuscated_config = bin2hex(gzdeflate(json_encode($config), 9));
        // Write Obfuscated $config Array to secure-config.php
        $fp = fopen($directory . "secure-config.php", 'w+');
        if ($fp == false)
            record_log("system", "Unable to open secure-config.php for writing.", true);

        // Write to secure-config.php File.
        $fw = fwrite($fp, $obfuscated_config);
        if ($fw == false)
            record_log("system", "Unable to write to secure-config.php.", true);

        // Close secure-config.php File.
        $fc = fclose($fp);
        if ($fc == false)
            record_log("system", "Unable to close secure-config.php for writing.", true);

        // Delete config.php once secure-config.php created.
        if (!unlink($directory . "config.php"))
            record_log("system", "Unable to delete config.php.", true);
    }
} else
    if (file_exists($directory . "secure-config.php")) {
        // De-Obfuscate Secure Config File.
        $config = json_decode(gzinflate(hex2bin(file_get_contents($directory .
            "secure-config.php"))), true);
    } else {
        // No Config Files Found.
        record_log("system",
            "config.php &#38; secure-config.php Are Missing. Ensure A Configuration File Exists.", true);
    }

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
foreach ($config_variables as $var) {
    if (!isset($config[$var]))
        record_log("system", "Variable &#36;config[&#34;" . $var .
            "&#34;] Missing From Config. Please Generate A New Configuration File Using config.php.new", true);
}

// Ensure Backup Destination is set to FTP.
if ($config["max_backups_per_account"] < 1)
    record_log("system",
        "&#36;config[&#34;max_backups_per_account&#34;] must be set to greater than 0.", true);

// Ensure Backup Destination is set to FTP.
if (($config["backup_destination"] != "ftp") || ($config["backup_destination"] != "passiveftp"))
    record_log("system",
        "You can only view/remove backups hosted on an FTP server. Config file is set to " .
        $config["backup_destination"] . ".", true);

// Empty Backups Array
$backups = array();

try {
    // Connect to FTP Server
    if (!$conn_id = ftp_connect($config['backup_hostname'], $config['backup_port'],
        20))
        record_log("system", "Unable to connect to FTP Server.", true);

    // Login to FTP Server
    if (!$login_result = ftp_login($conn_id, $config['backup_user'], $config['backup_pass']))
        record_log("system", "Unable to login to FTP Server.", true);

    // Enable Passive Mode?
    if (($config["backup_destination"] == "passiveftp") && (!$passive_mode = ftp_pasv($conn_id, true)))
        record_log("system", "Unable to login to connect to FTP server using Passive Mode.", true);

    // Retrieve Directory Listing
    if (!$contents = ftp_nlist($conn_id, $config['backup_rdir']))
        record_log("system", "Unable to retrieve file listing from FTP Server.", true);

    // Loop Through FTP Directory Listing
    // e.g. $list_key => $list_file_name (e.g. 0 => backup-month.day.year_hour-minute-second_username.tar.gz)
    foreach ($contents as $list_key => $list_file_name) {
        // Find Valid Backup Types
        if (fnmatch("backup-*.tar.gz", $list_file_name)) {
            // Extract Info From Filename
            $file_name = str_replace(array("backup-", ".tar.gz"), "", $list_file_name);
            list($backup_date, $backup_time, $backup_name) = explode("_", $file_name);

            // Create Unix Timestamp
            $d = DateTime::createFromFormat('n.j.Y H-i-s', $backup_date . " " . $backup_time);

            // Put Into Sorted Array
            $backups[$backup_name][$d->getTimestamp()] = $list_file_name;
        }
    }

    // Loop Through Accounts For Which Backups Exist
    ksort($backups);
    foreach ($backups as $account => $bkey) {
        $record_log_message = "";
        // Count Number of Backups For Specific Account.
        $total_backups = count($backups[$account]);

        // Sort Backups By Date, Oldest First
        ksort($bkey);
        //print_r($bkey);

        // Calculate The Number of Backups to Remove.
        $backups_to_remove = $total_backups - $config['max_backups_per_account'];

        // Check If Total Backups For Individual Account Is Greater Than 0.
        if ($total_backups == 0) {
            $record_log_message .= $account . "has no backups stored on the FTP server.";
        } else {
            $record_log_message .= $account . " has the following " . $total_backups .
                " backup(s) stored on the FTP server:";

            // Check How Many Backups For Individual Account Need Removing.
            if ($backups_to_remove > 0) {
                $record_log_message .= "\r\nThe " . $backups_to_remove .
                    " oldest backup(s) for " . $account . " will be removed.";

                // Loop Through Each Backup
                foreach ($bkey as $backup_timestamp => $backup_file) {

                    // Remove x Number of Oldest Backups For Account
                    if ($backups_to_remove > 0) {
                        $backups_to_remove = $backups_to_remove - 1;
                        // Remove Backup From Array.
                        unset($bkey[$backup_timestamp]);
                        // Delete Backup From FTP Server.
                        if (!ftp_delete($conn_id, $config['backup_rdir'] . DIRECTORY_SEPARATOR . $backup_file)) {
                            $record_log_message .= "\r\nUnable To Remove " . $config['backup_rdir'] .
                                DIRECTORY_SEPARATOR . $backup_file . " From FTP Server.";
                        } else {
                            $record_log_message .= "\r\n- " . $config['backup_rdir'] . DIRECTORY_SEPARATOR .
                                $backup_file . " has been removed.";
                        }

                    } else {
                        break;
                    }
                }
            } else {
                $record_log_message .= "\r\nNo backups need removing for this account.";
            }
        }
        record_log("note", $record_log_message);
    }
    if (!empty($config['backup_email'])) {
        $email_log = email_log("Backup Retention Log (WHM Backup Solutions)",
            "The FTP backup retention script has been run on " . $config['backup_hostname'] .
            ":" . $config['backup_port'] . $config['backup_rdir'] .
            ". The log is available below.\r\n");
        if ($email_log["error"] == "0") {
            record_log("note", "Log File Successfully Sent To " . $config["backup_email"]);
        } else {
            record_log("note", $email_log["response"], true);
        }
    } else {
        record_log("note", "Log File Completed.");
    }
}
catch (exception $e) {
    record_log("system", "FTP Retention Error: " . $e->getMessage(), true);
}

?>