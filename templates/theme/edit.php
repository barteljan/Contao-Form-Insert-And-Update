<?php
/**
 * @var Contao\FrontendTemplate $this
 */
$data = $this->getData();
$contentId = $data['id'];
$userGroups = FrontendUser::getInstance()->groups;
$allowedGroups = deserialize($data['member_edit_groups']);

$currentPageId = $this->replaceInsertTags('{{page::id}}');
\Contao\Session::getInstance()->set("jumpToPageId",$currentPageId);

$allowed = false;

if(is_array($userGroups) && is_array($allowedGroups)){
    foreach($userGroups as $userGroup){
        if(in_array($userGroup,$allowedGroups)){
            $allowed = true;
        }
    }
}
?>
<?php if($allowed):?>
<a href="bearbeiten.html?id=<?php echo $contentId;?>">edit</a><br />
<?php endif;?>