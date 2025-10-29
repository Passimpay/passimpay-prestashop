<img src="../modules/passimpay/logo.svg" style="float:left; margin-right:15px;">
<b>{$this->l('This module allows you to accept payments by Passimpay.')}</b><br/><br/>

<form action="{$action}" method="post">
    <fieldset>
        <legend><img src="../img/admin/contact.gif"/>{$this->l('Platform details')}</legend>
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
                <td colspan="2"><br/><hr style="border: 1px solid #ddd;"/><br/></td>
            </tr>
            <tr>
                <td width="140" style="vertical-align: top; padding-top: 5px;">
                    <strong>{$this->l('Notification URL')}</strong>
                </td>
                <td width="300">
                    <code style="display: block; background: #f5f5f5; padding: 8px; border: 1px solid #ddd; border-radius: 3px; font-size: 12px; word-break: break-all; user-select: all;">
                        {$webhook_url}
                    </code>
                    <small style="color: #666; display: block; margin-top: 5px;">
			{$this->l('Copy this URL and add it to your Passimpay dashboard under "Notification URL"')}
 		    </small>	    
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