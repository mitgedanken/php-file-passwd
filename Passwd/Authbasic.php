<?php
// +----------------------------------------------------------------------+
// | PEAR :: File :: Passwd :: Authbasic                                  |
// +----------------------------------------------------------------------+
// | This source file is subject to version 3.0 of the PHP license,       |
// | that is available at http://www.php.net/license/3_0.txt              |
// | If you did not receive a copy of the PHP license and are unable      |
// | to obtain it through the world-wide-web, please send a note to       |
// | license@php.net so we can mail you a copy immediately.               |
// +----------------------------------------------------------------------+
// | Copyright (c) 2003-2004 Michael Wallner <mike@iworks.at>             |
// +----------------------------------------------------------------------+
//
// $Id$

/**
* Manipulate AuthUserFiles as used for HTTP Basic Authentication.
*
* @author   Michael Wallner <mike@php.net>
* @package  File_Passwd
*/

/**
* Requires File::Passwd::Common
*/
require_once('File/Passwd/Common.php');

/**
* Manipulate AuthUserFiles as used for HTTP Basic Authentication.
*
* <kbd><u>
*   Usage Example:
* </u></kbd>
* <code>
*   $htp = &File_Passwd::factory('AuthBasic');
*   $htp->setMode('sha');
*   $htp->setFile('/www/mike/auth/.htpasswd');
*   $htp->load();
*   $htp->addUser('mike', 'secret');
*   $htp->save();
* </code>
* 
* <kbd><u>
*   Output of listUser()
* </u></kbd>
* <pre>
*      array
*       + user => crypted_passwd
*       + user => crypted_passwd
* </pre>
* 
* @author   Michael Wallner <mike@php.net>
* @package  File_Passwd
* @version  $Revision$
* @access   public
*/
class File_Passwd_Authbasic extends File_Passwd_Common
{
    /** 
    * Path to AuthUserFile
    *
    * @var string
    * @access private
    */
    var $_file = '.htpasswd';

    /** 
    * Actual encryption mode
    *
    * @var string
    * @access private
    */
    var $_mode = 'sha';

    /** 
    * Supported encryption modes
    *
    * @var array
    * @access private
    */
    var $_modes = array('md5' => 'm', 'des' => 'd', 'sha' => 's');

    /** 
    * Constructor
    * 
    * @access public
    * @param  string $file   path to AuthUserFile
    */
    function File_Passwd_Authbasic($file = '.htpasswd')
    {
        $this->__construct($file);
    }

    /**
    * Constructor (ZE2)
    * 
    * Rewritten because DES encryption is not 
    * supportet by the Win32 httpd.
    * 
    * @access protected
    * @param  string $file   path to AuthUserFile
    */
    function __construct($file = '.htpasswd')
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) == 'WIN') {
            unset($this->_modes['des']);
        }
        $this->setFile($file);
    }

    /**
    * Fast authentication of a certain user
    * 
    * Returns a PEAR_Error if:
    *   o file doesn't exist
    *   o file couldn't be opened in read mode
    *   o file couldn't be locked exclusively
    *   o file couldn't be unlocked (only if auth fails)
    *   o file couldn't be closed (only if auth fails)
    *
    * @static   call this method statically for a reasonable fast authentication
    * 
    * @throws   PEAR_Error
    * @access   public
    * @return   mixed   true if authenticated, false if not or PEAR_Error
    * @param    string  $file   path to passwd file
    * @param    string  $user   user to authenticate
    * @param    string  $pass   plaintext password
    * @param    string  $mode   des, sha or md5
    */
    function staticAuth($file, $user, $pass, $mode)
    {
        $line = File_Passwd_Common::_auth($file, $user);
        if (!$line || PEAR::isError($line)) {
            return $line;
        }
        list(,$real)    = explode(':', $line);
        $crypted        = File_Passwd_Authbasic::_genPass($pass, $real, $mode);
        if (PEAR::isError($crypted)) {
            return $crypted;
        }
        return ($real === $crypted);
    }
    
    /** 
    * Apply changes and rewrite AuthUserFile
    *
    * Returns a PEAR_Error if:
    *   o directory in which the file should reside couldn't be created
    *   o file couldn't be opened in write mode
    *   o file couldn't be locked exclusively
    *   o file couldn't be unlocked
    *   o file couldn't be closed
    * 
    * @throws PEAR_Error
    * @access public
    * @return mixed true on success or PEAR_Error
    */
    function save()
    {
        $content = '';
        foreach ($this->_users as $user => $pass) {
            $content .= $user . ':' . $pass . "\n";
        }
        return $this->_save($content);
    }

    /** 
    * Add an user
    *
    * The username must start with an alphabetical character and must NOT
    * contain any other characters than alphanumerics, the underline and dash.
    * 
    * Returns a PEAR_Error if:
    *   o user already exists
    *   o user contains illegal characters
    * 
    * @throws PEAR_Error
    * @access public
    * @return mixed true on success or PEAR_Error
    * @param string $user
    * @param string $pass
    */
    function addUser($user, $pass)
    {
        if ($this->userExists($user)) {
            return PEAR::raiseError(
                sprintf(FILE_PASSWD_E_EXISTS_ALREADY_STR, 'User ', $user),
                FILE_PASSWD_E_EXISTS_ALREADY
            );
        }
        if (!preg_match($this->_pcre, $user)) {
            return PEAR::raiseError(
                sprintf(FILE_PASSWD_E_INVALID_CHARS_STR, 'User ', $user),
                FILE_PASSWD_E_INVALID_CHARS
            );
        }
        $this->_users[$user] = $this->_genPass($pass);
        return true;
    }

    /** 
    * Change the password of a certain user
    *
    * Returns a PEAR_Error if user doesn't exist.
    * 
    * @throws PEAR_Error
    * @access public
    * @return mixed true on success or a PEAR_Error
    * @param string $user   the user whose password should be changed
    * @param string $pass   the new plaintext password
    */
    function changePasswd($user, $pass)
    {
        if (!$this->userExists($user)) {
            return PEAR::raiseError(
                sprintf(FILE_PASSWD_E_EXISTS_NOT_STR, 'User ', $user),
                FILE_PASSWD_E_EXISTS_NOT
            );
        }
        $this->_users[$user] = $this->_genPass($pass);
        return true;
    }

    /** 
    * Verify password
    *
    * Returns a PEAR_Error if:
    *   o user doesn't exist
    *   o an invalid encryption mode was supplied
    * 
    * @throws PEAR_Error
    * @access public
    * @return mixed true if passwords equal, false if they don't, or PEAR_Error
    * @param string $user   the user whose password should be verified
    * @param string $pass   the plaintext password to verify
    */
    function verifyPasswd($user, $pass)
    {
        if (!$this->userExists($user)) {
            return PEAR::raiseError(
                sprintf(FILE_PASSWD_E_EXISTS_NOT_STR, 'User ', $user),
                FILE_PASSWD_E_EXISTS_NOT
            );
        }
        $real = $this->_users[$user];
        return ($real === $this->_genPass($pass, $real));
    }

    /** 
    * Get actual encryption mode
    *
    * @access public
    * @return string
    */
    function getMode()
    {
        return $this->_mode;
    }

    /** 
    * Get supported encryption modes
    *
    * <pre>
    *   array
    *    + md5
    *    + sha
    *    + des
    * </pre>
    * 
    * ATTN: DES encryption not available on Win32!
    * 
    * @access public
    * @return array
    */
    function listModes()
    {
        return array_keys($this->_modes);
    }

    /** 
    * Set the encryption mode
    *
    * You can choose one of md5, sha or des.
    * 
    * ATTN: DES encryption not available on Win32!
    * 
    * Returns a PEAR_Error if a specific encryption mode is not supported.
    * 
    * @throws PEAR_Error
    * @access public
    * @return mixed true on succes or PEAR_Error
    * @param string $mode
    */
    function setMode($mode)
    {
        $mode = strToLower($mode);
        if (!isset($this->_modes[$mode])) {
            return PEAR::raiseError(
                sprintf(FILE_PASSWD_E_INVALID_ENC_MODE_STR, $this->_mode),
                FILE_PASSWD_E_INVALID_ENC_MODE
            );
        }
        $this->_mode = $mode;
        return true;
    }

    /**
    * Generate password with htpasswd executable
    * 
    * @access   private
    * @return   string  the crypted password
    * @param    string  $pass   the plaintext password
    * @param    string  $salt   the salt to use
    * @param    string  $mode   encyption mode, usually determined from
    *                           <var>$this->_mode</var>
    */
    function _genPass($pass, $salt = null, $mode = null)
    {
        $mode = is_null($mode) ? strToLower($this->_mode) : strToLower($mode);

        if ($mode == 'md5') {
            return File_Passwd::crypt_apr_md5($pass, $salt);
        } elseif ($mode == 'des') {
            return File_Passwd::crypt_des($pass, $salt);
        } elseif ($mode == 'sha') {
            return File_Passwd::crypt_sha($pass, $salt);
        }
        
        return PEAR::raiseError(
            sprintf(FILE_PASSWD_E_INVALID_ENC_MODE_STR, $mode),
            FILE_PASSWD_E_INVALID_ENC_MODE                
        );
    }
    
    /** 
    * Parse the AuthUserFile
    * 
    * Returns a PEAR_Error if AuthUserFile has invalid format.
    *
    * @throws PEAR_Error
    * @access public
    * @return mixed true on success or PEAR_error
    */
    function parse()
    {
        $this->_users = array();
        foreach ($this->_contents as $line) {
            $user = explode(':', $line);
            if (count($user) != 2) {
                return PEAR::raiseError(
                    FILE_PASSWD_E_INVALID_FORMAT_STR,
                    FILE_PASSWD_E_INVALID_FORMAT
                );
            }
            $this->_users[$user[0]] = trim($user[1]);
        }
        $this->_contents = array();
        return true;
    }
}
?>