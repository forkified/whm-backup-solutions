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
 *                  Curl
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
 * @filename    functions.php
 */

$version = "0.9";

/**
 * @name        check_version
 * @description Checks against https://checkversion.whmbackup.solutions if the script is running the latest version.
 * @global      $version        (string)    Version of Script (Read Only).
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (This may also contain a response if the script is out of date).
 *                      version_status - 0 - Running Latest Version
 *                                       1 - Major Version Out Of Date
 *                                       2 - Minor Version Out Of Date
 */
function check_version()
{
    global $version;
    $curl = curl_init();
    curl_setopt($curl, CURLOPT_URL, "https://checkversion.whmbackup.solutions/"); // https://whmbackup.solutions/check_version/ to be deprecated in January 2019.
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HEADER, false);
    curl_setopt($curl, CURLOPT_HTTPHEADER, array('Script-Version: ' . $version));
    curl_setopt($curl, CURLOPT_TIMEOUT, 5);
    $curl_data = curl_exec($curl);

    if ($curl_data === false)
        return array("error" => "1", "response" => "Curl Error During Update Check: " .
                curl_error($curl)); // Error During Curl

    $data = json_decode($curl_data, true);
    $script_version = explode(".", $version);
    if (($script_version["0"] == $data["version_major"]) && ($script_version["1"] ==
        $data["version_minor"]))
        return array(
            "error" => "0",
            "response" => "This Script Is Running The Latest Version.",
            "version_status" => "0"); // Up To Date

    if ($script_version["0"] < $data["version_major"])
        return array(
            "error" => "0",
            "response" => "This Script Is Running A Major Version Out Of Date. Not Updating May Affect The Ability To Keep Your Accounts Secure Or Actually Run The Script As cPanel Updates Their Software. Please Update As Soon As Possible.",
            "version_status" => "1"); // Out Of Date - Major Version

    if ($script_version["1"] < $data["version_minor"])
        return array(
            "error" => "0",
            "response" => "This Script Is Running A Minor Version Out Of Date. Ensure Updates Are Installed To Ensure Your Accounts Remain Secure.",
            "version_status" => "2"); // Out Of Date - Minor Version
    curl_close($curl);
}


/**
 * @name        record_log
 * @description Provides a standard method for creating and appending to a log file.
 * @global      $log_file       (string)    Filename of log (Read Only).
 * @global      $version        (string)    Version of Script (Read Only).
 * @global      $config         (array)     Config Values As Set in Config File (Read Only).
 * @param       $type           (string)    Can be set to either note or system.
 * @param       $log_message    (string)    Message to write to log.
 * @param       $stop           (boolean)   If set to true, will stop script running.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function record_log($type, $log_message, $stop = false)
{
    // Grab Variables From Global Scope.
    global $log_file, $version, $config, $directory;

    // Set Log Directory.
    $log_directory = $directory . "logs" . DIRECTORY_SEPARATOR;

    // Record all logs in system.log unless its related to a backup.
    $file_name = $log_directory . "system.log";
    if ($type == "note")
        $file_name = $log_directory . $log_file;

    if ((!isset($config["date_format"])) || (empty($config["date_format"])))
        $config['date_format'] = "d/m/Y g:i:s a";
    if ((!isset($config["timezone"])) || ($config["timezone"] != true))
        $config['timezone'] = date_default_timezone_set('Europe/London');

    // Prepare message to be written to log file.
    $message = "================================
WHM Backup Solutions (https://whmbackup.solutions) - V" . $version . "
" . date($config["date_format"], time()) . " - " . $log_message . "
";
    // Add Line Breaks For Clearer Console/HTML Output
    $output = str_replace("\n", "<br/>\n", $message);

    // Open Log File.
    $fp = fopen($file_name, 'a+');
    if ($fp == false)
        return array("error" => "1", "response" => "Unable To Open Log File (" . $file_name .
                ").");

    // Write to Log File.
    $fw = fwrite($fp, $message);
    if ($fw == false)
        return array("error" => "1", "response" => "Unable To Write to Log File (" . $file_name .
                ").");

    // Close Log File.
    $fc = fclose($fp);
    if ($fc == false)
        return array("error" => "1", "response" => "Unable To Close to Log File (" . $file_name .
                ").");
    // Echo $message.
    echo $output;

    // If Stop script is requested, stop the script running.
    if ($stop == true)
        exit();
}

/**
 * @name        retrieve_status
 * @description Determines if a backup is required or is running. Status is saved in backup_status.php.
 * @param       $config_name    (string)    The name of the config file to include e.g. config-NAME.php or secure-config-NAME.php.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 *                      status - 0 = No Backup Required
 *                               1 = Backup Running (Username of Accounts Remaining, Log File Returned)
 *                               2 = Backup Complete (Log File Returned)
 *                      account_list - List of accounts remaining to be backed up (If status == 2)
 *                      log_file     - Filename of log (If status == 1 or 2)
 */
function retrieve_status($config_name = null)
{
    global $directory;
    $status_contents = false;
    $config_file = "config";
    if ((isset($config_name)) && (!empty($config_name)))
        $config_file = preg_replace('/[^a-zA-Z0-9]/', '', $config_name);

    $file_name = $directory . "temp" . DIRECTORY_SEPARATOR . "status-" . $config_file .
        ".php";
    if (file_exists($file_name)) {
        $handle = fopen($file_name, "r"); //open file in read mode
        if (!$handle)
            return array("error" => "1", "response" => "Unable To Open Status File (" . $file_name .
                    ".).");
        $contents = fread($handle, filesize($file_name)); //read file
        if (!$contents)
            return array("error" => "1", "response" => "Unable To Read Status File (" . $file_name .
                    ".).");
        fclose($handle); //close file
        $status_contents = json_decode($contents, true); // Decode Status File.
    }
    if ((!isset($status_contents["log_file"])) || (empty($status_contents["log_file"]))) {
        $status = 0; // No Backup Required

    } else
        if (count($status_contents["account_list"]) > 0) {
            $status = 1; // Backup Running
        } else {
            $status = 2; // Backup Complete
        }


        $return = array(
            "error" => "0",
            "response" => "",
            "status" => $status,
            "account_list" => $status_contents["account_list"],
            "log_file" => $status_contents["log_file"]);
    return $return;
}

/**
 * @name        ftp_is_dir
 * @description Checks if the directory specified is valid.
 * @param       $conn_id    (FTP object)    An open FTP connection.
 * @return      true or false
 */
function ftp_is_dir($conn_id, $dir)
{
    $original_directory = @ftp_pwd($conn_id);
    if (@ftp_chdir($conn_id, $dir)) {
        // If it is a directory, then change the directory back to the original directory
        @ftp_chdir($conn_id, $original_directory);
        return true;
    }
    return false;
}

/**
 * @name        ftp_directory_creation
 * @description Creates the directory structure on the FTP server.
 * @param       $conn_id    (FTP object)    An open FTP connection.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function ftp_directory_creation($conn_id, $dir)
{
    $dir = str_ireplace("%20", " ", $dir);
    if (ftp_is_dir($conn_id, $dir)) {
        return array("error" => "0", "response" => "FTP Directory Exists.");
    }
    $directories = array_filter(explode("/", $dir));
    $cdir = $pdir = "/";
    foreach ($directories as $directory) {
        $cdir = $cdir . $directory . "/";
        if (!ftp_is_dir($conn_id, $cdir)) {
            if (!@ftp_mkdir($conn_id, $cdir)) {
                break;
                return array("error" => "1", "response" => "Failed To Create Directory (" . $cdir .
                        ").");
            }
        }
        $pdir = $cdir;
    }
    return array("error" => "0", "response" => "FTP Directory Creation Succeeded.");
}

/**
 * @name        ftp_verification
 * @description Verifies if PHP can login and list the files within the FTP server..
 * @global      $config         (array)     Config Values As Set in Config File (Read Only).
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function ftp_verification()
{
    global $config;

    if ((isset($config['skip_ftp_verification'])) && ($config['skip_ftp_verification'] ==
        "1"))
        return array("error" => "0", "response" => "FTP Verification Skipped.");

    try {
        if (!($conn_id = @ftp_connect($config['backup_hostname'], $config['backup_port'],
            5)))
            return array("error" => "1", "response" => "Unable To Connect To FTP Server (" .
                    $config['backup_hostname'] . ":" . $config['backup_port'] . ").");

        if (!@ftp_login($conn_id, $config['backup_user'], $config['backup_pass']))
            return array("error" => "1", "response" => "Unable To Login To FTP Server (" . $config['backup_hostname'] .
                    ":" . $config['backup_port'] . ").");

        $ftp_directory_creation = ftp_directory_creation($conn_id, $config['backup_rdir']);
        if ($ftp_directory_creation["error"] == "1")
            return array("error" => "1", "response" => $ftp_directory_creation["response"]);

        return array("error" => "0", "response" => "FTP Verification Succeeded.");
    }
    catch (exception $e) {
        return array("error" => "1", "response" => $e->getMessage());
    }
}

/**
 * @name        update_status
 * @description Save Status in backup_status.php.
 * @param       $account_list   (string)    List of accounts remaining to be backed up.
 * @param       $log_file       (string)    Filename of log
 * @param       $config_name    (string)    The name of the config file to include e.g. config-NAME.php or secure-config-NAME.php.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function update_status($account_list, $log_file, $config_name = null)
{
    global $directory;
    $store = json_encode(array("account_list" => $account_list, "log_file" => $log_file));

    $config_file = "config";
    if ((isset($config_name)) && (!empty($config_name)))
        $config_file = preg_replace('/[^a-zA-Z0-9]/', '', $config_name);

    $file_name = $directory . "temp" . DIRECTORY_SEPARATOR . "status-" . $config_file .
        ".php";
    $fp = fopen($file_name, 'w+');
    if ($fp == false)
        return array("error" => "1", "response" => "Unable To Open Status File (" . $file_name .
                ").");

    // Write to Status File.
    $fw = fwrite($fp, $store);
    if ($fw == false)
        return array("error" => "1", "response" => "Unable To Write to Status File (" .
                $file_name . ").");

    // Close Status File.
    $fc = fclose($fp);
    if ($fc == false)
        return array("error" => "1", "response" => "Unable To Close to Status File (" .
                $file_name . ").");
}

/**
 * @name        generate_account_list
 * @description Return an array containing the username of all accounts within the reseller as defined
 *              within the configuration file, it will also create a new log file.
 * @global      $config         (array)     Config Values As Set in Config File (Read Only).
 * @global      $xmlapi         (object)    XML-API Class.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 *                      account_list - An array containing the usernames of accounts to be backed up.
 *                      log_file     - Filename of log.
 */
function generate_account_list()
{
    // Set Variables
    global $config, $xmlapi;
    $accounts_to_backup = array();
    $accounts_to_exclude = array();
    $accounts_suspended = array();
    $valid_backup_types = array(
        "1" => "",
        "2" => "user",
        "3" => "plan",
        "4" => "domain",
        "5" => "owner",
        "6" => "ip");
    $backup_type = $valid_backup_types[$config["type_of_backup"]];

    // Get Backup Criteria/Exclusion Into Array From Config
    $backup_criteria = explode(",", $config["backup_criteria"]);
    $backup_exclusions = explode(",", $config["backup_exclusions"]);

    try {
        // Retrieve WHM Account List
        $xmlapi_listaccts = json_decode($xmlapi->listaccts(), true);
        if (empty($xmlapi_listaccts["status"]))
            return array(
                "error" => "1",
                "response" => "List Account - " . $xmlapi_listaccts["statusmsg"],
                "log_file" => "backup-" . date("YmdHis", time()) . ".log");
        if (isset($xmlapi_listaccts["cpanelresult"]["data"]["reason"]))
            return array(
                "error" => "1",
                "response" => "List Account - " . $xmlapi_listaccts["cpanelresult"]["data"]["reason"],
                "log_file" => "backup-" . date("YmdHis", time()) . ".log");
        if (!isset($xmlapi_listaccts["acct"]))
            return array(
                "error" => "1",
                "response" => "List Account - Unable To List Accounts",
                "log_file" => "backup-" . date("YmdHis", time()) . ".log");
        // Loops Through WHM Account List
        foreach ($xmlapi_listaccts["acct"] as $acct) {
            // If Backup Set To Anything But Backup All Accounts e.g. Backup By Domain Criteria Only
            if (!empty($backup_type)) {
                // Check If Account Is Specified To Be Backed Up
                // $acct[domain] => example.com
                // $backup_criteria => example.com
                if (in_array($acct[$backup_type], $backup_criteria)) {
                    // Check If Account Username Specified In Exclusion List
                    if (!in_array($acct["user"], $backup_exclusions)) {
                        // Check If Account Is Suspended
                        if ($acct["suspended"] != "0") {
                            $accounts_suspended[] = $acct["user"];
                        } else {
                            $accounts_to_backup[] = $acct["user"];
                        }
                    } else {
                        $accounts_to_exclude[] = $acct["user"];
                    }
                }
            } else {
                // If Backup Set To Backup All Accounts
                // Check If Account Username Specified In Exclusion List
                if (!in_array($acct["user"], $backup_exclusions)) {
                    // Check If Account Is Suspended
                    if ($acct["suspended"] != "0") {
                        $accounts_suspended[] = $acct["user"];
                    } else {
                        $accounts_to_backup[] = $acct["user"];
                    }
                } else {
                    $accounts_to_exclude[] = $acct["user"];
                }
            }
        }
        // Put Arrays In Descending Order
        asort($accounts_to_backup);
        asort($accounts_to_exclude);
        asort($accounts_suspended);

        return array(
            "error" => "0",
            "response" => "",
            "account_list" => $accounts_to_backup,
            "account_suspended" => $accounts_suspended,
            "account_excluded" => $accounts_to_exclude,
            "log_file" => "backup-" . date("YmdHis", time()) . ".log");
    }
    catch (exception $e) {
        return array(
            "error" => "1",
            "response" => $e->getMessage(),
            "log_file" => "backup-" . date("YmdHis", time()) . ".log");
    }
}

/**
 * @name        backup_accounts
 * @description Identifies the next account to be backed up (Alphabetical Order), then submits the
 *              account for processing.
 * @global      $log_file       (string)   Filename of log.
 * @param       $account_list   (array)    An array of usernames to be backed up.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function backup_accounts($account_list)
{
    global $xmlapi, $config;

    if (empty($config['backup_rdir']))
        $config['backup_rdir'] = "/";

    $api_args = array(
        $config['backup_destination'], // Destination Type
        $config['backup_hostname'], // Destination Hostname
        $config['backup_user'], // FTP/SCP Username
        $config['backup_pass'], // FTP/SCP Password
        $config['backup_email'], // Backup Email Address
        $config['backup_port'], // Destination Port
        $config['backup_rdir'] // Remote Path To Storage Directory
            );

    $result = json_decode($xmlapi->api1_query($account_list[0], 'Fileman',
        'fullbackup', $api_args), true);
    if (isset($result["cpanelresult"]["data"]["reason"]))
        return array("error" => "1", "response" => $result["cpanelresult"]["data"]["reason"]);
    if ((isset($result["cpanelresult"]["event"]["reason"])) && (!empty($result["cpanelresult"]["event"]["reason"])))
        return array("error" => "1", "response" => $result["cpanelresult"]["event"]["reason"]);
    if ($result["data"]["result"] == "0")
        return array("error" => "1", "response" => $result["data"]["reason"]);

    return array("error" => "0", "response" => "");
}

/**
 * @name        email_log
 * @description Sends the specified log file to the email address.
 * @global      $log_file       (string)   Filename of log.
 * @param       $subject        (string)   The subject of the email being sent.
 * @param       $message        (string)   The message of the email being sent.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function email_log($subject, $message)
{
    global $log_file, $config, $directory;
    $log_directory = $directory . "logs" . DIRECTORY_SEPARATOR;
    $file_name = $log_directory . $log_file;
    if (empty($log_file))
        return array("error" => "1", "response" =>
                "Unable To Send Notification Email, Log File Not Specified.");

    if (!file_exists($file_name))
        return array("error" => "1", "response" =>
                "Unable To Send Notification Email, Log File Does Not Exist (" . $file_name .
                ").");
    $handle = fopen($file_name, "r"); //open file in read mode
    if (!$handle)
        return array("error" => "1", "response" =>
                "Unable To Send Notification Email, Unable To Open Log File (" . $file_name .
                ".).");
    $contents = fread($handle, filesize($file_name)); //read file
    if (!$contents)
        return array("error" => "1", "response" =>
                "Unable To Send Notification Email, Unable To Read Log File (" . $file_name .
                ".).");
    fclose($handle); //close file

    $message = $message . $contents; // Stop lines being longer than 70 characters.


    if (mail($config["backup_email"], $subject, $message, "From: " . $config["backup_email"] .
        "\r\n") == false)
        return array("error" => "1", "response" =>
                "Unable To Send Notification Email, An Error Occured While Trying To Send The Email.");

    return array("error" => "0", "response" => "");
}

/**
 * @name        include_config
 * @description Reads, decrypts and returns $config.
 * @global      $directory      (string)   Directory of main script.
 * @param       $config_name    (string)   The name of the config file to include e.g. config-NAME.php or secure-config-NAME.php.
 * @return      (array) error - Boolean 1 or 0,
 *                      response - Error Message (if applicable).
 */
function include_config($config_name = null)
{
    global $directory;
    $config_file = "config.php";
    if ((isset($config_name)) && (!empty($config_name)))
        $config_file = "config-" . preg_replace('/[^a-zA-Z0-9]/', '', $config_name) .
            ".php";


    //Check Existance of Config and Include.
    if (file_exists($directory . $config_file)) {
        include ($directory . $config_file);

        if ((!isset($config)) || (!is_array($config)))
            return array("error" => "1", "response" => "&#36;config not defined in config.");

        if ($config["obfuscate_config"] == true) {
            $obfuscated_config = bin2hex(gzdeflate(json_encode($config), 9));
            $fp = fopen($directory . "secure-" . $config_file, 'w+');
            if ($fp == false)
                return array("error" => "1", "response" => "Unable to open secure-" . $config_file .
                        ".php for writing.");

            // Write to secure-config.php File.
            $fw = fwrite($fp, $obfuscated_config);
            if ($fw == false)
                return array("error" => "1", "response" => "Unable to write to secure-" . $config_file .
                        ".php.");

            // Close secure-config.php File.
            $fc = fclose($fp);
            if ($fc == false)
                return array("error" => "1", "response" => "Unable to close secure-" . $config_file .
                        ".php for writing.");

            if (!unlink($directory . $config_file))
                return array("error" => "1", "response" => "Unable to delete " . $config_file .
                        ".");
        }
    } else
        if (file_exists($directory . "secure-" . $config_file)) {
            // De-Obfuscate Secure Config File.
            $config = json_decode(gzinflate(hex2bin(file_get_contents($directory . "secure-" .
                $config_file))), true);
        } else {
            // No Config Files Found.
            return array("error" => "1", "response" => $config_file . " &#38; secure-" . $config_file .
                    " Are Missing. Ensure A Configuration File Exists.");
        }
        return array("error" => "0", "response" => $config);
}

?>