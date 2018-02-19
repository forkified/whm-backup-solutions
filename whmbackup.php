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
 * @filename    whmbackup.php
 */
$version = "0.3";
$directory = realpath(__dir__ ) . DIRECTORY_SEPARATOR;

// Include Functions file.
include ($directory . "resources" . DIRECTORY_SEPARATOR . "functions.php");
include ($directory . "resources" . DIRECTORY_SEPARATOR . "xmlapi" .
	DIRECTORY_SEPARATOR . "xmlapi.php");

//Check Existance of Config and Include.
if (file_exists($directory . "config.php"))
{
	include ($directory . "config.php");
	if ($config["obfuscate_config"] == true)
	{
		$obfuscated_config = bin2hex(gzdeflate(json_encode($config), 9));
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

		if (!unlink($directory . "config.php"))
			record_log("system", "Unable to delete config.php.", true);
	}
} else
	if (file_exists($directory . "secure-config.php"))
	{
		// De-Obfuscate Secure Config File.
		$config = json_decode(gzinflate(hex2bin(file_get_contents($directory .
			"secure-config.php"))), true);
	} else
	{
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
		"whm_hostname",
		"whm_port",
		"whm_username",
		"whm_auth",
		"whm_auth_key",
		"type_of_backup",
		"backup_criteria",
		"backup_exclusions",
		"backup_destination",
		"backup_hostname",
		"backup_port",
		"backup_user",
		"backup_pass",
		"backup_email",
		"backup_rdir");

// Check Config For All Required Variables.
foreach ($config_variables as $var)
{
	if (!isset($config[$var]))
		record_log("system", "Variable &#36;config[&#34;" . $var .
			"&#34;] Missing From Config. Please Generate A New Configuration File Using config.php.new", true);
}

// Variables
$generate = false;
$force = false;
if ((PHP_SAPI == 'cli') && (isset($argv)))
{
	$generate = in_array("generate", $argv);
	$force = in_array("force", $argv);
} else
	if (PHP_SAPI != 'cli')
	{
		$generate = array_key_exists("generate", $_GET);
		$force = array_key_exists("force", $_GET);
	}

// Retrieve Backup Status
$retrieve_status = retrieve_status();
if ($retrieve_status["error"] == "1")
	record_log("error", $retrieve_status["response"], true);

$log_file = $retrieve_status["log_file"];

try
{
	$xmlapi = new xmlapi($config["whm_hostname"]);
	if ($config["whm_auth"] == "password")
	{
		$xmlapi->password_auth($config["whm_username"], $config["whm_auth_key"]);
	} else
		if ($config["whm_auth"] == "hash")
		{
			$xmlapi->hash_auth($config["whm_username"], $config["whm_auth_key"]);
		} else
		{
			record_log("system", "Invalid Authentication Type, Set &0024;config[\"whm_auth\"] to either password or hash.", true);
		}

		$xmlapi->set_output('json');
	$xmlapi->set_debug(0);


}
catch (exception $e)
{
	record_log("system", "XML-API Error: " . $e->getMessage(), true);
}

// Generate Variable Set, If Backup Already Started & Force Variable Set OR If Backup Not Already Started, Generate Account List
if ((($generate == true) && ($retrieve_status["status"] == "1") && ($force == true)) ||
	(($generate == true) && ($retrieve_status["status"] != "1")))
{

	$generate_account_list = generate_account_list();
	$log_file = $generate_account_list["log_file"];
	if ($generate_account_list["error"] == "1")
		record_log("backup", "(Generation) ERROR: " . $generate_account_list["response"], true);

	if ($config["check_version"] != '0')
	{
		$check_version = check_version();
		if ($check_version["error"])
			record_log("backup", "UPDATE CHECK ERROR: " . $check_version["response"]);
		if (($config["check_version"] == $check_version["version_status"]) || (($config["check_version"] ==
			"2") && ($check_version["version_status"] == "1")))
		{
			record_log("backup", "UPDATE CHECK: " . $check_version["response"]);
		}
	}


	$save_status = update_status($generate_account_list["account_list"], $generate_account_list["log_file"]);
	if ($save_status["error"] == "1")
		record_log("backup", "(Generation) ERROR: " . $save_status["response"], true);
	record_log("backup", "Accounts To Be Backed Up: " . implode(", ", $generate_account_list["account_list"]), true);
}

if (($generate == true) && ($retrieve_status["status"] == "1"))
{
	echo "Backup Already Started. To Generate A New Backup Use Force Variable.";
}

if (($generate == false) && ($retrieve_status["status"] == "0"))
{
	echo "No Backups Required.";
}

// Generate Variable Not Set, Backup Already Started, Accounts Remaining To Backup.
if (($generate == false) && ($retrieve_status["status"] == "1"))
{
	$backup_accounts = backup_accounts($retrieve_status["account_list"]);
	$account = $retrieve_status["account_list"][0];
	//$retrieve_status["account_list"] = array_values($retrieve_status["account_list"]);
	unset($retrieve_status["account_list"][0]);
	$save_status = update_status(array_values($retrieve_status["account_list"]), $retrieve_status["log_file"]);

	if ($backup_accounts["error"] == "0")
		record_log("backup", "(" . $account .
			") Backup Initiated. For More Details See The Backup Email For This Account.", true);

	record_log("backup", "(" . $account . ") ERROR: " . $backup_accounts["response"], true);
}

// Generate Variable Not Set, Backup Already Started, All Accounts Backed Up, Send Log File in Email.
if (($generate == false) && ($retrieve_status["status"] == "2"))
{
	if (!empty($config['backup_email']))
	{
		$email_log = email_log();
		if ($email_log["error"] == "0")
		{
			record_log("backup", "Log File Successfully Sent To " . $config["backup_email"]);
		} else
		{
			record_log("backup", $email_log["response"], true);
		}
	}else{
	   record_log("backup", "Log File Completed.");
	}
	$update_status = update_status(array(), "");
}

?>