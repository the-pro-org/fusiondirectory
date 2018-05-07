<?php
/*
  This code is part of FusionDirectory (http://www.fusiondirectory.org/)
  Copyright (C) 2003-2010  Cajus Pollmeier
  Copyright (C) 2011-2016  FusionDirectory

  This program is free software; you can redistribute it and/or modify
  it under the terms of the GNU General Public License as published by
  the Free Software Foundation; either version 2 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU General Public License for more details.

  You should have received a copy of the GNU General Public License
  along with this program; if not, write to the Free Software
  Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA 02110-1301, USA.
*/

/* Load required includes */
require_once ("../include/php_setup.inc");
require_once ("functions.inc");
require_once ("variables.inc");
require_once ("class_logging.inc");
header("Content-type: text/html; charset=UTF-8");

/* Display the login page and exit() */
function displayLogin()
{
  global $smarty,$message,$config,$ssl,$error_collector,$error_collector_mailto;
  $lang = session::global_get('lang');

  error_reporting(E_ALL | E_STRICT);
  /* Fill template with required values */
  $username = '';
  if (isset($_POST['username'])) {
    $username = trim($_POST['username']);
  }
  $smarty->assign ('date',      gmdate('D, d M Y H:i:s'));
  $smarty->assign ('username',  $username);
  $smarty->assign ('revision',  FD_VERSION);
  $smarty->assign ('year',      date('Y'));
  $smarty->append ('css_files', get_template_path('login.css'));

  /* Some error to display? */
  if (!isset($message)) {
    $message = "";
  }
  $smarty->assign ("message", $message);

  /* Display SSL mode warning? */
  if (($ssl != '') && ($config->get_cfg_value('warnSSL') == 'TRUE')) {
    $smarty->assign ('ssl', sprintf(_('Warning: <a href="%s">Session is not encrypted!</a>'), $ssl));
  } else {
    $smarty->assign ('ssl', '');
  }

  if (!$config->check_session_lifetime()) {
    $smarty->assign ('lifetime', _('Warning: The session lifetime configured in your fusiondirectory.conf will be overridden by php.ini settings.'));
  } else {
    $smarty->assign ('lifetime', '');
  }

  /* Generate server list */
  $servers = array();
  if (isset($_POST['server'])) {
    $selected = $_POST['server'];
  } else {
    $selected = $config->data['MAIN']['DEFAULT'];
  }
  foreach ($config->data['LOCATIONS'] as $key => $ignored) {
    $servers[$key] = $key;
  }
  $smarty->assign ("server_options", $servers);
  $smarty->assign ("server_id", $selected);

  /* show login screen */
  $smarty->assign ("PHPSESSID", session_id());
  if (session::is_set('errors')) {
    $smarty->assign("errors", session::get('errors'));
  }
  if ($error_collector != "") {
    $smarty->assign("php_errors", preg_replace("/%BUGBODY%/", $error_collector_mailto, $error_collector)."</div>");
  } else {
    $smarty->assign("php_errors", "");
  }
  $smarty->assign("msg_dialogs", msg_dialog::get_dialogs());
  $smarty->assign("usePrototype", "false");
  $smarty->assign("date", date("l, dS F Y H:i:s O"));
  $smarty->assign("lang", preg_replace('/_.*$/', '', $lang));
  $smarty->assign("rtl",  Language::isRTL($lang));

  $smarty->display (get_template_path('headers.tpl'));
  $smarty->assign("version", FD_VERSION);

  $smarty->display(get_template_path('login.tpl'));
  exit();
}

/*****************************************************************************
 *                               M   A   I   N                               *
 *****************************************************************************/

/* Set error handler to own one, initialize time calculation
   and start session. */
session::start();

if (isset($_REQUEST['signout']) && $_REQUEST['signout']) {
  if (session::global_is_set('connected')) {
    $config = session::global_get('config');
    if ($config->get_cfg_value('casActivated') == 'TRUE') {
      require_once('CAS.php');
      /* Move FD autoload after CAS autoload */
      spl_autoload_unregister('__fusiondirectory_autoload');
      spl_autoload_register('__fusiondirectory_autoload');
      phpCAS::client(
        CAS_VERSION_2_0,
        $config->get_cfg_value('casHost', 'localhost'),
        (int)($config->get_cfg_value('casPort', 443)),
        $config->get_cfg_value('casContext', '')
      );
      // Set the CA certificate that is the issuer of the cert
      phpCAS::setCasServerCACert($config->get_cfg_value('casServerCaCertPath'));
      phpCas::logout();
    }
  }
  session::destroy();
  session::start();
}

/* Reset errors */
session::set('errors', '');
session::set('errorsAlreadyPosted', '');
session::set('LastError', '');

/* Check if we need to run setup */
if (!file_exists(CONFIG_DIR.'/'.CONFIG_FILE)) {
  header('location:setup.php');
  exit();
}

/* Check if fusiondirectory.conf (.CONFIG_FILE) is accessible */
if (!is_readable(CONFIG_DIR.'/'.CONFIG_FILE)) {
  msg_dialog::display(
    _('Configuration error'),
    sprintf(
      _('FusionDirectory configuration %s/%s is not readable. Please run fusiondirectory-setup --check-config to fix this.'),
      CONFIG_DIR,
      CONFIG_FILE
    ),
    FATAL_ERROR_DIALOG
  );
  exit();
}

/* Parse configuration file */
$config = new config(CONFIG_DIR.'/'.CONFIG_FILE, $BASE_DIR);
session::global_set('config', $config);
session::global_set('DEBUGLEVEL', $config->get_cfg_value('DEBUGLEVEL'));
@DEBUG (DEBUG_CONFIG, __LINE__, __FUNCTION__, __FILE__, $config->data, 'config');

/* Set template compile directory */
$smarty->compile_dir = $config->get_cfg_value('templateCompileDirectory', SPOOL_DIR);

/* Check for compile directory */
if (!(is_dir($smarty->compile_dir) && is_writable($smarty->compile_dir))) {
  msg_dialog::display(
    _('Smarty error'),
    sprintf(
      _('Directory "%s" specified as compile directory is not accessible!'),
      $smarty->compile_dir
    ),
    FATAL_ERROR_DIALOG
  );
  exit();
}

/* Check for old files in compile directory */
clean_smarty_compile_dir($smarty->compile_dir);

Language::init();

$smarty->assign ('focusfield', 'username');

if (isset($_POST['server'])) {
  $server = $_POST['server'];
} else {
  $server = $config->data['MAIN']['DEFAULT'];
}

$config->set_current($server);
if (
  ($config->get_cfg_value('casActivated') == 'TRUE') ||
  ($config->get_cfg_value('httpAuthActivated') == 'TRUE') ||
  ($config->get_cfg_value('httpHeaderAuthActivated') == 'TRUE')) {
  session::global_set('DEBUGLEVEL', 0);
}

/* If SSL is forced, just forward to the SSL enabled site */
if (($config->get_cfg_value('forcessl') == 'TRUE') && ($ssl != '')) {
  header ("Location: $ssl");
  exit;
}

if (isset($_REQUEST['message'])) {
  switch ($_REQUEST['message']) {
    case 'expired':
      $message = _('Your FusionDirectory session has expired!');
      break;
    case 'invalidparameter':
      $message = sprintf(_('Invalid plugin parameter "%s"!'), $_REQUEST['plug']);
      break;
    case 'nosession':
      $message = _('No session found!');
      break;
    default:
      $message = $_REQUEST['message'];
  }
}

$loginMethods = LoginMethod::getMethods();
foreach ($loginMethods as $method) {
  if ($method::active()) {
    $method::loginProcess();
  }
}

/* Translation of cookie-warning. Whether to display it, is determined by JavaScript */
$smarty->assign ('cookies', '<b>'._('Warning').':</b> '._('Your browser has cookies disabled. Please enable cookies and reload this page before logging in!'));

/* Set focus to the error button if we've an error message */
$focus = '';
if (session::is_set('errors') && session::get('errors') != '') {
  $focus = '<script type="text/javascript">';
  $focus .= 'document.forms[0].error_accept.focus();';
  $focus .= '</script>';
}
$smarty->assign('focus', $focus);

displayLogin();
?>

</body>
</html>
