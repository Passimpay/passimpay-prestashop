<img src="../modules/passimpay/logo.png" style="float:left; margin-right:15px;">
<b>{$this->l('This module allows you to accept payments by Passimpay.')}</b><br/><br/>
<form action="{$action}" method="post">
    <fieldset>
        <legend><img src="../img/admin/contact.gif"/>{$this->l('Contact details')}</legend>
        <table border="0" width="500" cellpadding="0" cellspacing="0" id="form">
            <tr>
                <td colspan="2">{$this->l('Please specify required data')}<br/><br/></td>
            </tr>
            <tr>
                <td width="140" style="height: 35px;">{$this->l('Platform ID')}</td>
                <td><input type="text" name="pp_platform_id"
                           value="{$platform_id}"
                           style="width: 300px;"/></td>
            </tr>
            <tr>
                <td width="140" style="height: 35px;">{$this->l('Secret key')}</td>
                <td><input type="text" name="pp_secret_key"
                           value="{$secret_key}"
                           style="width: 300px;"/></td>
            </tr>
            <tr>
                <td width="140" style="height: 35px;">{$this->l('Language')}</td>
                <td>
                    <select name="pp_language">
                        {html_options options=$languageList selected=$language}
                    </select>
                </td>
            </tr>
            <tr>
                <td colspan="2" align="center"><br/><input class="button" name="btnSubmit"
                                                           value="{$this->l('Update settings')}" type="submit"/></td>
            </tr>
        </table>
    </fieldset>
</form>

<script type="text/javascript" src="/modules/passimpay/views/templates/hook/settings.js"></script>

<style>
    .hidden {
        display: none;
    }
</style>