<?php
/**
 * Copyright (c) 2014, Jan Bartel
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * Redistributions of source code must retain the above copyright notice, this
 *  list of conditions and the following disclaimer.
 *
 * Redistributions in binary form must reproduce the above copyright notice,
 *  this list of conditions and the following disclaimer in the documentation
 *  and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE
 * FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL
 * DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY,
 * OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * @package   FormInsertAndUpdate
 * @author    Jan Bartel <barteljan@yahoo.de>
 * @license   BSD
 * @copyright Jan Bartel 2014
 */
namespace jba\form\saveAndUpdate;

use Contao\Database;
use Contao\Messages;

class FormSaveAndUpdateFrontendProcessor extends \Frontend{

  /**
   * Process submitted form data
   * Send mail, store data in backend
   * @param array $arrSubmitted Submitted data
   * @param array|bool $arrForm Form configuration
   * @param array|bool $arrFiles Files uploaded
   * @param array|bool $arrLabels Form field labels
   * @return void
   */
  public function processSubmittedData($arrSubmitted, $arrForm=false, $arrFiles=false, $arrLabels=false) {

      //check if we should storeAndUpdate this form
      //check if table and alias are set
      if(intval($arrForm['storeAndUpdateValues']) != 1 &&
         strlen($arrForm['storeAndUpdateTable']) == 0 &&
         strlen($arrForm['storeAndUpdateAlias']) == 0){
           return;
      }

      $table = $arrForm['storeAndUpdateTable'];
      $alias = $arrForm['storeAndUpdateAlias'];

      $this->import('FrontendUser','frontendUser');
      $this->import('Database','database');

      //check if table exists
      if(!$this->database->tableExists($table,null,true)){
        return;
      }

      //read available database fields from db
      $fieldList = $this->database->listFields($table,true);

      $dbFields = array();
      foreach($fieldList as $field){
        $dbFields[$field['name']] = $field;
      }

      //check if alias field exists
      if(!isset($dbFields[$alias])){
          \Message::addError($dbFields[$alias]." existiert nicht!");
          return;
      }

      //check if we should insert or update
      if((is_string($arrSubmitted[$alias]) && strlen($arrSubmitted[$alias]) > 0) ||
          (is_numeric($arrSubmitted[$alias]) && double_val($arrSubmitted[$alias]) > 0)){

          $aliasValue = (is_numeric($arrSubmitted[$alias])?intval($arrSubmitted[$alias]):preg_replace("/[^a-zA-Z0-9\-]+/", "", $arrSubmitted[$alias]));

          /**
           * @var Database $database
           */
          $database   = $this->database;

          //check if we are allowed to update
          if(!empty($arrForm["storeAndUpdateEditPermissionField"])){

              //check if permission field exists
              if(!isset($dbFields[$arrForm["storeAndUpdateEditPermissionField"]])) {
                  \Message::addError($arrForm["storeAndUpdateEditPermissionField"] . " does not exist!");
                  return;
              }

              $sql = "SELECT ".$arrForm["storeAndUpdateEditPermissionField"]." FROM ".$table." WHERE ".$alias." = ".$aliasValue;
              echo $sql."<br>";
              $result = $database->query($sql);

              $row = $result->fetchAssoc();
              $editPermissions = deserialize($row[$arrForm["storeAndUpdateEditPermissionField"]]);

              if($editPermissions != null && !is_array($editPermissions)){
                  \Message::addError($arrForm["storeAndUpdateEditPermissionField"] . " is of wrong type!");
                  return;
              }

              $userGroups = \contao\FrontendUser::getInstance()->groups;
              $allowedGroups = deserialize($editPermissions);

              $allowed = false;

              if(is_array($userGroups) && is_array($allowedGroups)){
                  foreach($userGroups as $userGroup){
                      if(in_array($userGroup,$allowedGroups)){
                          $allowed = true;
                      }
                  }
              }

              if(!$allowed){
                  \Message::addError("Access not allowed!");
                  return;
              }
          }

          $this->update($table,$alias,$arrSubmitted,$alias);
      }else{
          $this->insert($table,$alias,$arrSubmitted);
      }
  }

  protected function prepareDataArray($arrSubmitted,$table,$alias,$type){
    $arrSet = array();

    // Add the timestamp
    if ($this->Database->fieldExists('tstamp', $table))
    {
      $arrSet['tstamp'] = time();
    }

    // Fields
    foreach ($arrSubmitted as $k=>$v)
    {
      if ($k != 'cc' && $k != 'id' && $k != 'FORM_SUBMIT' && $k != 'REQUEST_TOKEN')
      {
        $arrSet[$k] = $v;
      }
    }

    // Files
    if (!empty($_SESSION['FILES']))
    {
      foreach ($_SESSION['FILES'] as $k=>$v)
      {
        if ($v['uploaded'])
        {
          $arrSet[$k] = str_replace(TL_ROOT . '/', '', $v['tmp_name']);
        }
      }
    }

    // HOOK: store form data callback
    if (isset($GLOBALS['TL_HOOKS']['storeAndUpdateFormData']) && is_array($GLOBALS['TL_HOOKS']['storeFormData']))
    {
      foreach ($GLOBALS['TL_HOOKS']['storeAndUpdateFormData'] as $callback)
      {
        $this->import($callback[0]);
        /**
        *  @var array  arrSet Values which will be inserted
        *  @var String type Type of storage operation ('Insert' or Update)
        *  @var String table Table to store data in
        *  @var String alias Name of field to identifie one data item
        *  @var array  arrSubmitted Submitted form data
        *  @var array  arrForm formConfiguration
        *  @var array  arrFiles uploaded files
        **/
        $arrSet = $this->$callback[0]->$callback[1]($arrSet,$type,$table,$alias,$arrSubmitted,$arrForm,$arrFiles);
      }
    }

    // Set the correct empty value (see #6284, #6373)
    foreach ($arrSet as $k=>$v)
    {
      if ($v === '')
      {
        $arrSet[$k] = \Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$table]['fields'][$k]['sql']);
      }
    }

    return $arrSet;
  }


  protected function update($table,$alias,$arrSubmitted,$aliasFieldName){
    $type   = 'UPDATE';

    $arrSet = $this->prepareDataArray($arrSubmitted,$table,$alias,$type);

    $this->Database
         ->prepare('UPDATE '. $table .' %s WHERE '.$aliasFieldName.'=?')
         ->set($arrSet)
         ->execute($arrSubmitted[$aliasFieldName]);

  }

  protected function insert($table,$alias,$arrSubmitted){
    $type   = 'INSERT';

    $arrSet = $this->prepareDataArray($arrSubmitted,$table,$alias,$type);

    $this->Database->prepare("INSERT INTO " . $table . " %s")->set($arrSet)->execute();
  }


}
