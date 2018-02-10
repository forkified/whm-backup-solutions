===============================================================
WHM Backup Solutions - https://whmbackup.solutions
===============================================================

===============================================================
Script Files
===============================================================
	whmbackup.php
	config.php.new
	secure-config.php.new
	LICENSE.txt
	resources/functions.php
	resources/xmlapi/xmlapi.php
	temp/
	logs/
===============================================================

===============================================================
Installation
===============================================================
A quick summary of installation instructions is listed below.
For more detailed instructions visit https://whmbackup.solutions/installation/

1. Download the latest version from https://whmbackup.solutions.

2. Extract files and rename config.php.new to config.php

3. Enter details into config.php.

4. Upload all files outside of public web directory.

5. Setup Cron Jobs
	- Generate Backup List
		This cronjob should run as frequent as you want
		backups taking e.g. once a day/week/month etc.

		Command: php -q /home/user/whmbackupsolutions/whmbackup.php generate

		(Please note if you have register_argv_argc disabled
		on your server this will not work. A work around is
		available at https://whmbackup.solutions/installation/)

	- Run Account Backup
		This cronjob should run every 5, 10 or 15 minutes.
		We recommend setting this to every 5 minutes but
		if there are accounts larger than 2GB then the time
		between runs should be increased to reduce server load.

		Command: php -q /home/user/whmbackupsolutions/whmbackup.php

===============================================================

===============================================================
Developer
===============================================================
This script is developed and maintained by Peter Kelly
<peter@whmbackup.solutions>.
If you are struggling to install the script, experience error
messages or have suggestions to improve the script please get
in contact via the facebook page https://www.facebook.com/whmbackupsolutions/
or email me peter@whmbackup.solutions.
===============================================================

===============================================================
License
===============================================================
This script is licensed under GNU GPL-3.0-or-later for more
information see https://whmbackup.solutions/LICENSE.txt
===============================================================