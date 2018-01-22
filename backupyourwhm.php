<?php

function record_log($error, $log)
{
	// TODO: Record Log
	die("");
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

?>