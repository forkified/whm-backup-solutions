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
$directory = realpath(__dir__ ) . DIRECTORY_SEPARATOR;

// Include Functions file.
include ($directory . "resources" . DIRECTORY_SEPARATOR . "functions.php");
include ($directory . "resources" . DIRECTORY_SEPARATOR . "xmlapi" . DIRECTORY_SEPARATOR . "xmlapi.php");

// Variables
$generate = null;
$force = null;
$config_name = null;

// Check if script run via command line or web browser.
if ((PHP_SAPI == 'cli'))
{
	foreach ($argv as $arg)
	{
		list($arg_x, $arg_y) = explode('=', $arg);
		$_GET[$arg_x] = $arg_y;
	}

}

// Check if script variables are set in $_GET.
$generate = array_key_exists("generate", $_GET);
$force = array_key_exists("force", $_GET);
if (array_key_exists("config", $_GET))
	$config_name = $_GET["config"];

// Include Config File
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

// Retrieve Backup Status
$retrieve_status = retrieve_status($config_name);
if ($retrieve_status["error"] == "1"){
	record_log("system", $retrieve_status["response"]);
    $email_log = email_log("ERROR - Reseller Backup Log (WHM Backup Solutions)", "The backup of \"" . $config['whm_username'] .
			"\" has an error that required attention. The log of backup initiation is available below.\r\n", TRUE);
	if ($email_log["error"] == "0")
	{
		record_log("note", "Retrieve Status Log File Successfully Sent To " . $config["backup_email"]);
	} else
	{
		record_log("note", "Retrieve Status " . $email_log["response"], true);
	}
    exit();
}

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
			record_log("system", "Invalid Authentication Type, Set &#36;config[\"whm_auth\"] to either password or hash.");
            $email_log = email_log("ERROR - Reseller Backup Log (WHM Backup Solutions)", "The backup of \"" . $config['whm_username'] .
			"\" has an error that required attention. The log of backup initiation is available below.\r\n", TRUE);
            if ($email_log["error"] == "0")
            {
                record_log("note", "Invalid Authentication Log File Successfully Sent To " . $config["backup_email"]);
            } else
            {
                record_log("note", "Invalid Authentication " . $email_log["response"], true);
            }
            exit();
		}

	$xmlapi->set_output('json');
	$xmlapi->set_debug(0);

// Generate Variable Set, If Backup Already Started & Force Variable Set OR If Backup Not Already Started, Generate Account List
if ((($generate == true) && ($retrieve_status["status"] == "1") && ($force == true)) || (($generate == true) &&
	($retrieve_status["status"] != "1")))
{
    
	$update_status = update_status(array(), "", $config_name);

	// Generate Account List
	$generate_account_list = generate_account_list();
	$log_file = $generate_account_list["log_file"];
    
    $cpanel_version = json_decode($xmlapi->version(), true);
    if(!isset($cpanel_version["version"])) $cpanel_version["version"] = "Error";

    if((isset($cpanel_version["status"])) && ($cpanel_version["status"] == "0")) $cpanel_version["version"] = $cpanel_version["statusmsg"];
    record_log("note", $config['whm_username'] . "@" . $config['whm_hostname'] . ", cPanel Version: " . $cpanel_version["version"]);
    
	if ($generate_account_list["error"] == "1"){
		record_log("note", "(Generation) ERROR: " . $generate_account_list["response"]);
        $email_log = email_log("ERROR - Reseller Backup Log (WHM Backup Solutions)", "The backup of \"" . $config['whm_username'] .
			"\" has an error that required attention. The log of backup initiation is available below.\r\n");
		if ($email_log["error"] == "0")
		{
			record_log("note", "Generation Log File Successfully Sent To " . $config["backup_email"]);
		} else
		{
			record_log("note", "Generation " . $email_log["response"], true);
		}
        exit();
    }

	// Check For New Version
	if ($config["check_version"] != '0')
	{
		$check_version = check_version();
		if ($check_version["error"] == "1")
		{
			record_log("note", "UPDATE CHECK ERROR: " . $check_version["response"]);
		} else
		{
			if (($config["check_version"] == $check_version["version_status"]) || (($config["check_version"] == "2") &&
				($check_version["version_status"] == "1")))
			{
				record_log("note", "UPDATE CHECK: " . $check_version["response"]);
				if ((!isset($config["skip_update"])) || ($config["skip_update"] != "1"))
				{
					$update_script = update_script($check_version["hash"]);
                    if($update_script["error"] == "1"){
                        record_log("note", "UPDATE SCRIPT ERROR: " . $update_script["response"]);
                    }else{
                        record_log("note", "UPDATE SCRIPT: " . $update_script["response"]);
                    }
				}
			}
		}
	}

	$save_status = update_status($generate_account_list["account_list"], $generate_account_list["log_file"], $config_name);
	if ($save_status["error"] == "1")
		record_log("note", "(Generation) ERROR: " . $save_status["response"], true);
	record_log("note", "Accounts To Be Backed Up: " . implode(", ", $generate_account_list["account_list"]), false);
	if (count($generate_account_list["account_excluded"]) > 0)
		record_log("note", "Accounts Excluded From Backup By Config: " . implode(", ", $generate_account_list["account_excluded"]), false);
	if (count($generate_account_list["account_suspended"]) > 0)
		record_log("note", "Accounts Excluded From Backup Due To Being Suspended: " . implode(", ", $generate_account_list["account_suspended"]), false);
	exit();
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
	$save_status = update_status(array_values($retrieve_status["account_list"]), $retrieve_status["log_file"], $config_name);

	if (($backup_accounts["error"] == "0") && (!empty($config["backup_email"]))){
        if(isset($backup_accounts["pid"])){
            record_log("note", "(" . $account .
			") Backup Initiated (PID: " . $backup_accounts["pid"] . "). For More Details See The Backup Email For This Account.", true);
        }else{
            record_log("note", "(" . $account .
			") Backup Initiated. For More Details See The Backup Email For This Account.", true);
        }
    }

	if (($backup_accounts["error"] == "0") && (empty($config["backup_email"]))){
        if(isset($backup_accounts["pid"])){
            record_log("note", "(" . $account . ") Backup Initiated (PID: " . $backup_accounts["pid"] . ").", true);
        }else{
            record_log("note", "(" . $account . ") Backup Initiated.", true);
        }
    }

	record_log("note", "(" . $account . ") ERROR: " . $backup_accounts["response"], true);
}

// Generate Variable Not Set, Backup Already Started, All Accounts Backed Up, Send Log File in Email.
if (($generate == false) && ($retrieve_status["status"] == "2"))
{
	if (!empty($config['backup_email']))
	{
		$email_log = email_log("Reseller Backup Log (WHM Backup Solutions)", "The backup of \"" . $config['whm_username'] .
			"\" has been completed. The log of backup initiation is available below.\r\n");
		if ($email_log["error"] == "0")
		{
			record_log("note", "Backup Completion Log File Successfully Sent To " . $config["backup_email"]);
		} else
		{
			record_log("note", "Backup Completion " . $email_log["response"], true);
		}
	} else
	{
		record_log("note", "Log File Completed.");
	}
	$update_status = update_status(array(), "", $config_name);
}

}
catch (exception $e)
{
	record_log("system", "cPanel API Error: " . $e->getMessage());
    $email_log = email_log("ERROR - Reseller Backup Log (WHM Backup Solutions)", "The backup of \"" . $config['whm_username'] .
			"\" has an error that required attention. The log of backup initiation is available below.\r\n", TRUE);
	if ($email_log["error"] == "0")
	{
		record_log("note", "cPanel API Error Log File Successfully Sent To " . $config["backup_email"]);
	} else
	{
		record_log("note", "cPanel API Error " . $email_log["response"], true);
	}
    exit();
}

?>