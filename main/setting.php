<?if(!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED!==true)die();?>
<?
//***********************************
//setting section
//***********************************

// ИСПРАВЛЕННАЯ ЛОГИКА: Получаем текущее значение согласия на рассылку
$newsletterAgreement = 'N'; // По умолчанию ВЫКЛЮЧЕНО

if ($USER->IsAuthorized()) {
    global $USER;
    $userID = $USER->GetID();
    if ($userID) {
        $rsUser = CUser::GetByID($userID);
        if ($arUser = $rsUser->Fetch()) {
            // Проверяем пользовательское поле
            $fieldValue = $arUser["UF_NEWSLETTER_AGREEMENT"] ?? '';
            
            // Приводим к единому формату
            if ($fieldValue == '1' || $fieldValue == 'Y' || $fieldValue === 1 || $fieldValue === true) {
                $newsletterAgreement = 'Y';
            } else {
                $newsletterAgreement = 'N';
            }
        }
    }
} else {
    // Для неавторизованных пользователей - по умолчанию включено только при новой подписке
    if (!$arResult["ID"]) {
        $newsletterAgreement = 'Y'; // Только для новых подписок
    }
}
?>
<form action="<?=$arResult["FORM_ACTION"]?>" method="post">
<?echo bitrix_sessid_post();?>
<table width="100%" border="0" cellpadding="0" cellspacing="0" class="data-table top">
<thead><tr><td colspan="2"><h4><?echo GetMessage("subscr_title_settings")?></h4></td></tr></thead>
<tr valign="top">
	<td class="left_blocks" style="width: 100% !important;">
		<div class="form-control">
			<label><?echo GetMessage("subscr_email")?> <span class="star">*</span></label>
			<input type="text" name="EMAIL" value="<?=$arResult["SUBSCRIPTION"]["EMAIL"]!=""?$arResult["SUBSCRIPTION"]["EMAIL"]:$arResult["REQUEST"]["EMAIL"];?>" size="30" maxlength="255" />
		</div>
		<div class="adaptive more_text">
			<div class="more_text_small">
				<?echo GetMessage("subscr_settings_note1")?><br/>
				<?echo GetMessage("subscr_settings_note2")?>
			</div>
		</div>
		<h5><?echo GetMessage("subscr_rub")?><span class="star">*</span></h5/>
		<div class="filter label_block">
			<?foreach($arResult["RUBRICS"] as $itemID => $itemValue):?>
				<input type="checkbox" name="RUB_ID[]" id="RUB_ID_<?=$itemValue["ID"]?>" value="<?=$itemValue["ID"]?>"<?if($itemValue["CHECKED"]) echo " checked"?> />
				<label for="RUB_ID_<?=$itemValue["ID"]?>"><?=$itemValue["NAME"]?></label>
			<?endforeach;?>
		</div>
		<h5><?echo GetMessage("subscr_fmt")?></h5>
		<div class="filter label_block radio">
			<input type="radio" name="FORMAT" id="txt" value="text"<?if($arResult["SUBSCRIPTION"]["FORMAT"] == "text") echo " checked"?> /><label for="txt"><?echo GetMessage("subscr_text")?></label>&nbsp;/&nbsp;<input type="radio" name="FORMAT" id="html" value="html"<?if($arResult["SUBSCRIPTION"]["FORMAT"] == "html") echo " checked"?> /><label for="html">HTML</label>
		</div>

		<!-- СОГЛАСИЕ НА РАССЫЛКУ -->
		<h5>Согласие на рассылку</h5>
		<div class="filter label_block">
			<input type="checkbox" id="newsletter_agreement" name="NEWSLETTER_AGREEMENT" value="Y" <?=($newsletterAgreement == 'Y' || $newsletterAgreement == '1') ? 'checked' : ''?> />
			<label for="newsletter_agreement">
				Согласие на получение рекламной информации по электронной почте и SMS
			</label>
		</div>
		<div class="more_text_small">
			<a href="/company/soglasheniya/podpiska-na-reklamu/" target="_blank">Подробнее о согласии на рассылку</a>
		</div>
	</td>
	<td class="right_blocks" style="display: none;">
		<div class="more_text_small">
			<?echo GetMessage("subscr_settings_note1")?><br/>
			<?echo GetMessage("subscr_settings_note2")?>
		</div>
	</td>
</tr>
<tfoot><tr><td colspan="2">
	<?global $arTheme;?>
	<?if($arTheme["SHOW_LICENCE"]["VALUE"] == "Y" && !$arResult["ID"] ):?>
		<div class="subscribe_licenses">
			<div class="licence_block filter label_block" style="display: none;">
				<input type="checkbox" id="licenses_subscribe" <?=($newsletterAgreement == 'Y' || $newsletterAgreement == '1') ? 'checked' : ''?> name="licenses_subscribe" value="Y">
				<label for="licenses_subscribe">
					<?$APPLICATION->IncludeFile(SITE_DIR."include/licenses_text.php", Array(), Array("MODE" => "html", "NAME" => "LICENSES")); ?>
				</label>
			</div>
		</div>
	<?endif;?>
	<div class="form-control">
		<?$APPLICATION->IncludeFile(SITE_DIR."include/required_message.php", Array(), Array("MODE" => "html"));?>
	</div>
	
	<!-- НАША КНОПКА (видимая) -->
	<button type="button" id="save_all_btn" class="btn btn-default">
		<?echo ($arResult["ID"] > 0? GetMessage("subscr_upd"):GetMessage("subscr_add"))?>
	</button>
	
	<!-- ОРИГИНАЛЬНАЯ КНОПКА (скрытая) -->
	<input type="submit" name="Save" id="original_save_btn" class="btn btn-default" value="<?echo ($arResult["ID"] > 0? GetMessage("subscr_upd"):GetMessage("subscr_add"))?>" style="display: none;" />
	<input type="reset" class="btn btn-default white" value="<?echo GetMessage("subscr_reset")?>" name="reset" />

<script>
document.addEventListener('DOMContentLoaded', function() {
    var saveAllBtn = document.getElementById('save_all_btn');
    var originalSaveBtn = document.getElementById('original_save_btn');
    var newsletterCheckbox = document.getElementById('newsletter_agreement');
    var licensesCheckbox = document.getElementById('licenses_subscribe');
    
    // Синхронизация значений при загрузке страницы
    if (newsletterCheckbox && licensesCheckbox) {
        licensesCheckbox.checked = newsletterCheckbox.checked;
    }
    
    // АВТОМАТИЧЕСКОЕ СОХРАНЕНИЕ при изменении галочки
    if (newsletterCheckbox) {
        newsletterCheckbox.addEventListener('change', function() {
            var isChecked = this.checked;
            
            // Синхронизируем с лицензиями
            if (licensesCheckbox) {
                licensesCheckbox.checked = isChecked;
            }
            
            // Показываем индикатор загрузки
            var originalText = newsletterCheckbox.parentElement.querySelector('label').innerHTML;
            newsletterCheckbox.parentElement.querySelector('label').innerHTML = 'Сохранение...';
            newsletterCheckbox.disabled = true;
            
            // Отправляем AJAX запрос для сохранения согласия
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/local/ajax/save_newsletter_agreement.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                newsletterCheckbox.disabled = false;
                newsletterCheckbox.parentElement.querySelector('label').innerHTML = originalText;
                
                console.log('Response status:', xhr.status);
                console.log('Response text:', xhr.responseText);
                
                if (xhr.status === 200) {
                    try {
                        var response = JSON.parse(xhr.responseText);
                        console.log('Agreement save response:', response);
                        
                        if (response.success) {
                            // Показываем сообщение об успехе
                            showMessage('Согласие сохранено!', 'success');
                        } else {
                            // При ошибке возвращаем галочку в исходное состояние
                            newsletterCheckbox.checked = !isChecked;
                            if (licensesCheckbox) {
                                licensesCheckbox.checked = !isChecked;
                            }
                            showMessage('Ошибка сохранения: ' + (response.error || response.message || 'Неизвестная ошибка'), 'error');
                        }
                    } catch (e) {
                        console.error('Parse error:', e);
                        console.log('Raw response:', xhr.responseText);
                        // При ошибке парсинга возвращаем галочку
                        newsletterCheckbox.checked = !isChecked;
                        if (licensesCheckbox) {
                            licensesCheckbox.checked = !isChecked;
                        }
                        showMessage('Ошибка обработки ответа сервера', 'error');
                    }
                } else {
                    // HTTP ошибка
                    newsletterCheckbox.checked = !isChecked;
                    if (licensesCheckbox) {
                        licensesCheckbox.checked = !isChecked;
                    }
                    showMessage('Ошибка сервера (HTTP ' + xhr.status + ')', 'error');
                }
            };
            
            xhr.onerror = function() {
                newsletterCheckbox.disabled = false;
                newsletterCheckbox.parentElement.querySelector('label').innerHTML = originalText;
                
                // При ошибке возвращаем галочку в исходное состояние
                newsletterCheckbox.checked = !isChecked;
                if (licensesCheckbox) {
                    licensesCheckbox.checked = !isChecked;
                }
                showMessage('Ошибка соединения с сервером', 'error');
            };
            
            xhr.send('agreement=' + (isChecked ? 'Y' : 'N'));
        });
    }
    
    // Функция показа сообщений
    function showMessage(text, type) {
        var messageDiv = document.createElement('div');
        messageDiv.className = 'ajax-message ' + type;
        messageDiv.innerHTML = text;
        messageDiv.style.cssText = 'position: fixed; top: 20px; right: 20px; background: ' + 
            (type === 'success' ? '#d4edda' : '#f8d7da') + '; color: ' + 
            (type === 'success' ? '#155724' : '#721c24') + '; padding: 10px 15px; border-radius: 4px; z-index: 9999;';
        
        document.body.appendChild(messageDiv);
        
        // Убираем сообщение через 3 секунды
        setTimeout(function() {
            if (messageDiv.parentNode) {
                messageDiv.parentNode.removeChild(messageDiv);
            }
        }, 3000);
    }
    
    // Обработчик кнопки сохранения всей формы
    if (saveAllBtn && originalSaveBtn) {
        saveAllBtn.addEventListener('click', function() {
            var isChecked = newsletterCheckbox ? newsletterCheckbox.checked : false;
            
            // Показываем что идет сохранение
            saveAllBtn.textContent = 'Сохранение...';
            saveAllBtn.disabled = true;
            
            // Сначала сохраняем согласие
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/local/ajax/save_newsletter_agreement.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            
            xhr.onload = function() {
                console.log('Agreement save response:', xhr.responseText);
                
                // После сохранения согласия нажимаем настоящий submit
                originalSaveBtn.click();
            };
            
            xhr.onerror = function() {
                console.error('Agreement save failed');
                // Даже при ошибке сохранения согласия продолжаем с основной формой
                originalSaveBtn.click();
            };
            
            xhr.send('agreement=' + (isChecked ? 'Y' : 'N'));
        });
    }
});
</script>
</td></tr></tfoot>
</table>
<input type="hidden" name="PostAction" value="<?echo ($arResult["ID"]>0? "Update":"Add")?>" />
<input type="hidden" name="ID" value="<?echo $arResult["SUBSCRIPTION"]["ID"];?>" />
<?if($_REQUEST["register"] == "YES"):?>
	<input type="hidden" name="register" value="YES" />
<?endif;?>
<?if($_REQUEST["authorize"]=="YES"):?>
	<input type="hidden" name="authorize" value="YES" />
<?endif;?>
	<input type="hidden" name="check_condition" value="YES" />
</form>
<br />