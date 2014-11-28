<?php
/**
 * Created by PhpStorm.
 * User: bartel
 * Date: 28.11.14
 * Time: 19:27
 */

class Form extends \Contao\Form{

    private $jumpToPage;

    /**
     * Sets a page model where the form should jump to
     * @param PageModel $jumpToPage
     */
    public function setJumpToPage(\PageModel $jumpToPage){
        $this->jumpToPage = $jumpToPage;
    }

    /**
     * @return \PageModel The page model to jump to
     */
    public function getJumpToPage(){
        return $this->jumpToPage;
    }

    /**
     * Process form data, store it in the session and redirect to the jumpTo page
     * @param array
     * @param array
     */
    protected function processFormData($arrSubmitted, $arrLabels)
    {
        // HOOK: prepare form data callback
        if (isset($GLOBALS['TL_HOOKS']['prepareFormData']) && is_array($GLOBALS['TL_HOOKS']['prepareFormData']))
        {
            foreach ($GLOBALS['TL_HOOKS']['prepareFormData'] as $callback)
            {
                $this->import($callback[0]);
                $this->$callback[0]->$callback[1]($arrSubmitted, $arrLabels, $this);
            }
        }

        // Send form data via e-mail
        if ($this->sendViaEmail)
        {
            $keys = array();
            $values = array();
            $fields = array();
            $message = '';

            foreach ($arrSubmitted as $k=>$v)
            {
                if ($k == 'cc')
                {
                    continue;
                }

                $v = deserialize($v);

                // Skip empty fields
                if ($this->skipEmpty && !is_array($v) && !strlen($v))
                {
                    continue;
                }

                // Add field to message
                $message .= (isset($arrLabels[$k]) ? $arrLabels[$k] : ucfirst($k)) . ': ' . (is_array($v) ? implode(', ', $v) : $v) . "\n";

                // Prepare XML file
                if ($this->format == 'xml')
                {
                    $fields[] = array
                    (
                        'name' => $k,
                        'values' => (is_array($v) ? $v : array($v))
                    );
                }

                // Prepare CSV file
                if ($this->format == 'csv')
                {
                    $keys[] = $k;
                    $values[] = (is_array($v) ? implode(',', $v) : $v);
                }
            }

            $recipients = \String::splitCsv($this->recipient);

            // Format recipients
            foreach ($recipients as $k=>$v)
            {
                $recipients[$k] = str_replace(array('[', ']', '"'), array('<', '>', ''), $v);
            }

            $email = new \Email();

            // Get subject and message
            if ($this->format == 'email')
            {
                $message = $arrSubmitted['message'];
                $email->subject = $arrSubmitted['subject'];
            }

            // Set the admin e-mail as "from" address
            $email->from = $GLOBALS['TL_ADMIN_EMAIL'];
            $email->fromName = $GLOBALS['TL_ADMIN_NAME'];

            // Get the "reply to" address
            if (strlen(\Input::post('email', true)))
            {
                $replyTo = \Input::post('email', true);

                // Add name
                if (strlen(\Input::post('name')))
                {
                    $replyTo = '"' . \Input::post('name') . '" <' . $replyTo . '>';
                }

                $email->replyTo($replyTo);
            }

            // Fallback to default subject
            if (!strlen($email->subject))
            {
                $email->subject = $this->replaceInsertTags($this->subject, false);
            }

            // Send copy to sender
            if (strlen($arrSubmitted['cc']))
            {
                $email->sendCc(\Input::post('email', true));
                unset($_SESSION['FORM_DATA']['cc']);
            }

            // Attach XML file
            if ($this->format == 'xml')
            {
                $objTemplate = new \FrontendTemplate('form_xml');

                $objTemplate->fields = $fields;
                $objTemplate->charset = \Config::get('characterSet');

                $email->attachFileFromString($objTemplate->parse(), 'form.xml', 'application/xml');
            }

            // Attach CSV file
            if ($this->format == 'csv')
            {
                $email->attachFileFromString(\String::decodeEntities('"' . implode('";"', $keys) . '"' . "\n" . '"' . implode('";"', $values) . '"'), 'form.csv', 'text/comma-separated-values');
            }

            $uploaded = '';

            // Attach uploaded files
            if (!empty($_SESSION['FILES']))
            {
                foreach ($_SESSION['FILES'] as $file)
                {
                    // Add a link to the uploaded file
                    if ($file['uploaded'])
                    {
                        $uploaded .= "\n" . \Environment::get('base') . str_replace(TL_ROOT . '/', '', dirname($file['tmp_name'])) . '/' . rawurlencode($file['name']);
                        continue;
                    }

                    $email->attachFileFromString(file_get_contents($file['tmp_name']), $file['name'], $file['type']);
                }
            }

            $uploaded = strlen(trim($uploaded)) ? "\n\n---\n" . $uploaded : '';
            $email->text = \String::decodeEntities(trim($message)) . $uploaded . "\n\n";

            // Send the e-mail
            try
            {
                $email->sendTo($recipients);
            }
            catch (\Swift_SwiftException $e)
            {
                $this->log('Form "' . $this->title . '" could not be sent: ' . $e->getMessage(), __METHOD__, TL_ERROR);
            }
        }

        // Store the values in the database
        if ($this->storeValues && $this->targetTable != '')
        {
            $arrSet = array();

            // Add the timestamp
            if ($this->Database->fieldExists('tstamp', $this->targetTable))
            {
                $arrSet['tstamp'] = time();
            }

            // Fields
            foreach ($arrSubmitted as $k=>$v)
            {
                if ($k != 'cc' && $k != 'id')
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
            if (isset($GLOBALS['TL_HOOKS']['storeFormData']) && is_array($GLOBALS['TL_HOOKS']['storeFormData']))
            {
                foreach ($GLOBALS['TL_HOOKS']['storeFormData'] as $callback)
                {
                    $this->import($callback[0]);
                    $arrSet = $this->$callback[0]->$callback[1]($arrSet, $this);
                }
            }

            // Set the correct empty value (see #6284, #6373)
            foreach ($arrSet as $k=>$v)
            {
                if ($v === '')
                {
                    $arrSet[$k] = \Widget::getEmptyValueByFieldType($GLOBALS['TL_DCA'][$this->targetTable]['fields'][$k]['sql']);
                }
            }

            // Do not use Models here (backwards compatibility)
            $this->Database->prepare("INSERT INTO " . $this->targetTable . " %s")->set($arrSet)->execute();
        }

        // Store all values in the session
        foreach (array_keys($_POST) as $key)
        {
            $_SESSION['FORM_DATA'][$key] = $this->allowTags ? \Input::postHtml($key, true) : \Input::post($key, true);
        }

        $arrFiles = $_SESSION['FILES'];

        // HOOK: process form data callback
        if (isset($GLOBALS['TL_HOOKS']['processFormData']) && is_array($GLOBALS['TL_HOOKS']['processFormData']))
        {
            foreach ($GLOBALS['TL_HOOKS']['processFormData'] as $callback)
            {
                $this->import($callback[0]);
                $this->$callback[0]->$callback[1]($arrSubmitted, $this->arrData, $arrFiles, $arrLabels, $this);
            }
        }

        $_SESSION['FILES'] = array(); // DO NOT CHANGE

        // Add a log entry
        if (FE_USER_LOGGED_IN)
        {
            $this->import('FrontendUser', 'User');
            $this->log('Form "' . $this->title . '" has been submitted by "' . $this->User->username . '".', __METHOD__, TL_FORMS);
        }
        else
        {
            $this->log('Form "' . $this->title . '" has been submitted by ' . \System::anonymizeIp(\Environment::get('ip')) . '.', __METHOD__, TL_FORMS);
        }

        if($this->getJumpToPage()!==null){
            $this->jumpToOrReload($this->getJumpToPage()->row());
        }
        // Check whether there is a jumpTo page
        else if (($objJumpTo = $this->objModel->getRelated('jumpTo')) !== null)
        {
            $this->jumpToOrReload($objJumpTo->row());
        }

        $this->reload();
    }
} 