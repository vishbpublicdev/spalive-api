<?php
/**
 * CakePHP(tm) : Rapid Development Framework (https://cakephp.org)
 * Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @copyright     Copyright (c) Cake Software Foundation, Inc. (https://cakefoundation.org)
 * @link          https://cakephp.org CakePHP(tm) Project
 * @since         0.10.0
 * @license       https://opensource.org/licenses/mit-license.php MIT License
 * @var \App\View\AppView $this
 */

echo `<center style="background-color: #f9f9f9; padding: 20px;">
    <div style="max-width: 570px; align-content: center; background-color: #f2f8f9 !important; padding: 56px; -webkit-box-shadow: 0px 0px 8px 1px #888888 !important; -moz-box-shadow: 0px 0px 8px 1px #888888 !important; box-shadow: 0px 0px 8px 1px #888888 !important;">
        <span style="font-family: Arial, Helvetica, sans-serif; font-size: 20px; color: #52a1cf; padding-top: 15px; display: inline-block; margin: 60px 0 3px 0; text-align: left !important;"> You have new message from <b>`.$from_user.`</b>.<br> </span>
        <p style="font-size: 15px; text-align: justify; color: #596163;">
            <b>`.$message.`</b>.
        </p>
    </div>
</center>`;