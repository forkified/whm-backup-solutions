<?php

function check_license()
{
	// TODO: Check Valid License
}

function record_log($error, $log)
{
	// TODO: Record Log
	die("");
}

function retrieve_accounts(){
    // TODO: Retrieve Accounts
}

/**
 * Check Existance of Config and Include.
 */
if (file_exists("config.php"))
{
	include ("config.php");
} else
	if (file_exists("secure-config.php"))
	{
		include ("secure-config.php");
		// TODO: Decrypt Secure Config.
	} else
	{
		record_log("error", "config.php &amp; secure-config.php are missing. Ensure a configuration file exists.");
	}

	/**
	 * Check License is Valid.
	 */
	if (!$check_license = check_license($license_key))
	{
		record_log("error", "License Error:" . $check_license);
	}

/**
 * Check Config Variables Exist
 */
$config_variables = array(
	"whm_hostname",
	"whm_port",
	"whm_username",
	"whm_auth",
	"whm_auth_key",
	"type_of_backup",
	"backup_criteria",
	"backup_exclusions",
	"backup_destination",
	"backup_server",
	"backup_port",
	"backup_user",
	"backup_pass",
	"backup_email",
	"backup_rdir",
	"backup_log");

foreach ($config_variables as $var)
{
	if (!isset($$var))
		record_log("error", "Variable Missing From Config. Try Use Config.new or Secure-Config.new.");
}

$retrieve_reseller_accounts = retrieve_accounts();

?>