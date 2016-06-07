<?php
/*
 * Copyright 2007-2013 Charles du Jeu - Abstrium SAS <team (at) pyd.io>
 * This file is part of Pydio.
 *
 * Pydio is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Pydio is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Pydio.  If not, see <http://www.gnu.org/licenses/>.
 *
 * The latest code can be found at <http://pyd.io/>.
 */

use Pydio\Access\Core\AbstractAccessDriver;
use Pydio\Access\Core\Model\Repository;
use Pydio\Auth\Core\AJXP_Safe;
use Pydio\Core\Model\ContextInterface;
use Pydio\Core\Utils\VarsFilter;
use Pydio\Meta\Core\AJXP_AbstractMetaSource;

defined('AJXP_EXEC') or die('Access not allowed');

/**
 * Dynamically mount a remote folder when switching to the repository
 * @package AjaXplorer_Plugins
 * @subpackage Meta
 *
 */
class FilesystemMounter extends AJXP_AbstractMetaSource
{
    /**
     * @var Repository
     */
    protected $repository;

    /**
     * @param ContextInterface $ctx
     * @param AbstractAccessDriver $accessDriver
     */
    public function beforeInitMeta(ContextInterface $ctx, AbstractAccessDriver $accessDriver)
    {
        $this->accessDriver = $accessDriver;
        $this->repository = $ctx->getRepository();
        if($this->isAlreadyMounted($ctx)) {
            return;
        }
        $this->mountFS($ctx);
    }

    /**
     * @param ContextInterface $ctx
     * @param AbstractAccessDriver $accessDriver
     */
    public function initMeta(ContextInterface $ctx, AbstractAccessDriver $accessDriver)
    {
        parent::initMeta($ctx, $accessDriver);
        $this->repository = $this->accessDriver->repository;
    }

    protected function getCredentials()
    {
        // 1. Try from plugin config
        $user = $this->options["USER"];
        $password = $this->options["PASS"];
        // 1BIS : encoded?
        if ($user == "" && isSet($this->options["ENCODED_CREDENTIALS"])) {
            list($user,$password) = AJXP_Safe::getCredentialsFromEncodedString($this->options["ENCODED_CREDENTIALS"]);
        }
        // 2. Try from session
        if ($user=="" &&  isSet($this->options["USE_SESSION_CREDENTIALS"]) ) {
            $safeCred = AJXP_Safe::loadCredentials();
            if ($safeCred !== false) {
                $user = $safeCred["user"];
                $password = $safeCred["password"];
            } else {
                throw new Exception("Session credential are empty! Did you forget to check the Set Session Credential in the Authentication configuration panel?");
            }
        }
        return array($user, $password);
    }

    protected function getOption(ContextInterface $ctx, $name, $user="", $pass="", $escapePass = true)
    {
        $opt = $this->options[$name];
        $opt = str_replace("AJXP_USER", $user, $opt);
        if($escapePass)  $opt = str_replace("AJXP_PASS",  "'$pass'", $opt);
        else $opt = str_replace("AJXP_PASS",  $pass, $opt);
        $opt = str_replace("AJXP_SERVER_UID", posix_getuid(), $opt);
        $opt = str_replace("AJXP_SERVER_GID", posix_getgid(), $opt);
        if (stristr($opt, "AJXP_REPOSITORY_PATH") !== false) {
            $repo = $this->repository;
            $path = $repo->getContextOption($ctx, "PATH");
            $opt = str_replace("AJXP_REPOSITORY_PATH", $path, $opt);
        }
        $opt = VarsFilter::filter($opt, $ctx);
        return $opt;
    }

    protected function isAlreadyMounted(ContextInterface $contextInterface)
    {
        list($user, $password) = $this->getCredentials();
        $MOUNT_POINT = $this->getOption($contextInterface, "MOUNT_POINT", $user, $password);
        if( is_dir($MOUNT_POINT) ){
            $statParent = stat(dirname($MOUNT_POINT));
            $statMount = stat($MOUNT_POINT);
            // Compare device id's
            if( $statParent[0] == $statMount[0] ){
                return false;
            }else{
                return true;
            }
        }else{
            return false;
        }
    }

    public function mountFS(ContextInterface $ctx)
    {
        list($user, $password) = $this->getCredentials();
        $this->logDebug("FSMounter::mountFS Should mount" . $user);
        $repo = $this->repository;

        if(isset($this->options["MOUNT_DEVIL"]) && !empty($this->options["MOUNT_DEVIL"]) && $this->options["MOUNT_DEVIL"]) {
            $udevil = "udevil ";
        }else{
            $udevil = "";
        }

        $MOUNT_TYPE = $this->options["FILESYSTEM_TYPE"];
        $MOUNT_POINT = $this->getOption($ctx, "MOUNT_POINT", $user, $password);
        $MOUNT_POINT_ROOT = $this->getOption($ctx, "MOUNT_POINT", "", "");
        $create = $repo->getContextOption($ctx, "CREATE");
        if ( $MOUNT_POINT != $MOUNT_POINT_ROOT && !is_dir($MOUNT_POINT_ROOT) && $create) {
            @mkdir($MOUNT_POINT_ROOT, 0755);
        }
        $recycle = false;
        if (!is_dir($MOUNT_POINT) && $create) {
            @mkdir($MOUNT_POINT, 0755);
        } else {
            if ($repo->getContextOption($ctx, "RECYCLE_BIN") != "") {
                // Make sure the recycle bin was not mounted inside the mount point!
                $recycle = $repo->getContextOption($ctx, "PATH")."/".$repo->getContextOption($ctx, "RECYCLE_BIN");
                if (@is_dir($recycle)) {
                    @rmdir($recycle);
                }
            }
        }
        $UNC_PATH = $this->getOption($ctx, "UNC_PATH", $user, $password, false);
        $MOUNT_OPTIONS = $this->getOption($ctx, "MOUNT_OPTIONS", $user, $password, false);

        $cmd = $udevil."mount -t " .$MOUNT_TYPE. (empty( $MOUNT_OPTIONS )? " " : " -o " .escapeshellarg($MOUNT_OPTIONS). " " ) .escapeshellarg($UNC_PATH). " " .escapeshellarg($MOUNT_POINT);
        $res = null;
        if($this->getOption($ctx, "MOUNT_ENV_PASSWD") == true){
            putenv("PASSWD=$password");
        }
        system($cmd, $res);
        if($this->getOption($ctx, "MOUNT_ENV_PASSWD") == true){
            putenv("PASSWD=");
        }
        $resultsOptions = str_replace(" ", "", $this->getOption($ctx, "MOUNT_RESULT_SUCCESS"));
        $acceptedResults = array(0);
        if(!empty($resultsOptions)){
            $acceptedResults = array_merge($acceptedResults, array_map("intval", explode(",", $resultsOptions)));
        }

        if($res === null){
            // Check it is correctly mounted now!
            // Could not get the output return code
            $cmd1 = "mount | grep ".escapeshellarg($MOUNT_POINT);
            $output = shell_exec($cmd1);
            $success = !empty($output);
        }else{
            $success = (in_array($res, $acceptedResults));
        }
        if (!$success) {
            throw new Exception("Error while mounting file system!");
        } else {
            if ($recycle !== false && !is_dir($recycle)) {
                @mkdir($recycle, 0755);
            }
        }
    }

    public function umountFS(ContextInterface $contextInterface)
    {
        $this->logDebug("FSMounter::unmountFS");
        list($user, $password) = $this->getCredentials();
        $MOUNT_POINT = $this->getOption($contextInterface, "MOUNT_POINT", $user, $password);

        if(isset($this->options["MOUNT_DEVIL"]) && !empty($this->options["MOUNT_DEVIL"]) && $this->options["MOUNT_DEVIL"]) {
            $udevil = "udevil ";
        }else{
            $udevil = "";
        }
        system($udevil."umount ".escapeshellarg($MOUNT_POINT), $res);
        if($this->getOption($contextInterface, "REMOVE_MOUNTPOINT_ON_UNMOUNT") == true && $res == 0 && !$this->isAlreadyMounted($contextInterface) ){
            // Remove mount point
            $testRm = @rmdir($MOUNT_POINT);
            if($testRm === false){
                $this->logError("[umount]", "Error while trying to delete mount point on unmount");
            }
        }
        return true;
    }

}
