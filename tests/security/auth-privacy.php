<?php
// Behavioral regressions for the legacy form and logbook authorization.
require_once $root . '/classes/tfyh_toolbox.php';
require_once $root . '/classes/tfyh_form.php';
require_once $root . '/classes/efa_info.php';

$toolbox = (new ReflectionClass(Tfyh_toolbox::class))->newInstanceWithoutConstructor();
$form = (new ReflectionClass(Tfyh_form::class))->newInstanceWithoutConstructor();
foreach (['toolbox' => $toolbox, 'form_definition' => [
    'Account' => ['name' => 'Account', 'label' => 'Account', 'type' => 'text', 'required' => '*'],
    'Passwort' => ['name' => 'Passwort', 'label' => 'Password', 'type' => 'password', 'required' => ''],
]] as $key => $value) {
    (new ReflectionProperty(Tfyh_form::class, $key))->setValue($form, $value);
}
$form->fs_id = 'test1';
$previousPost = $_POST;
foreach (["  Secret9;<`  ", 'abcdef0123456789abcdef0123456789'] as $password) {
    $_POST = ['Account' => ' clubadmin ', 'Passwort' => $password];
    $form->read_entered();
    expect_true($_SESSION['forms']['test1']['Passwort'] === $password,
        'password input preserves exact bytes, including punctuation and spaces');
    expect_eq($_SESSION['forms']['test1']['Account'], 'clubadmin', 'account is still trimmed');
    expect_eq($form->check_validity(0), '', 'login validation accepts an existing password');
}
expect_true($form->check_validity() !== '', 'new-password validation still enforces policy');
$_POST = $previousPost;
unset($_SESSION['forms']['test1']);

$info = (new ReflectionClass(Efa_info::class))->newInstanceWithoutConstructor();
$config = new class {
    public $setting = '';
    public function get_cfg() {
        return array_fill_keys(['public_onthewater', 'public_reserved', 'public_notusable', 'public_notavailable'], $this->setting);
    }
};
$toolbox->config = $config;
$toolbox->users = (object) ['anonymous_role' => 'anonymous'];
(new ReflectionProperty(Efa_info::class, 'toolbox'))->setValue($info, $toolbox);
foreach (['', 'off', 'on'] as $setting) {
    $config->setting = $setting;
    foreach (['onthewater', 'reserved', 'notusable', 'notavailable'] as $type) {
        foreach ([$type, 'public_' . $type] as $requestedType) {
            expect_false($info->is_allowed_info(['Rolle' => 'anonymous'], $requestedType),
                "anonymous cannot read $requestedType even with legacy setting '$setting'");
            expect_false($info->is_allowed_info([], $requestedType), 'missing identity fails closed');
            expect_true($info->is_allowed_info(['Rolle' => 'member'], $requestedType),
                'authenticated member retains logbook access');
        }
    }
}
