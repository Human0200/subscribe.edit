<?php
header('Content-Type: application/json; charset=utf-8');

// Логирование
function writeLog($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data) {
        $logMessage .= ' - ' . json_encode($data, JSON_UNESCAPED_UNICODE);
    }
    file_put_contents(__DIR__.'/working_debug.txt', $logMessage . "\n", FILE_APPEND);
}

try {
    //writeLog('Script started', $_POST);
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод должен быть POST');
    }
    
    if (!isset($_POST['agreement'])) {
        throw new Exception('Отсутствует параметр agreement');
    }
    
    // Подключаем Битрикс
    if (!file_exists($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php")) {
        throw new Exception('Файл prolog_before.php не найден');
    }
    
    require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
    
    if (!$USER->IsAuthorized()) {
        throw new Exception('Пользователь не авторизован');
    }
    
    if (!CModule::IncludeModule("iblock")) {
        throw new Exception('Модуль инфоблоков не загружен');
    }
    
    $userID = $USER->GetID();
    $agreement = ($_POST['agreement'] === 'Y') ? 'Y' : 'N'; // Сохраняем как Y/N
    
   // writeLog('Processing user', ['userID' => $userID, 'agreement' => $agreement]);
    
    // Получаем данные пользователя
    $userObj = new CUser;
    $userData = $userObj->GetByID($userID)->Fetch();
    
    if (!$userData) {
        throw new Exception('Данные пользователя не найдены');
    }
    
    // Обновляем согласие пользователя
    $user = new CUser;
    $updateResult = $user->Update($userID, ['UF_NEWSLETTER_AGREEMENT' => $agreement]);
    
    if (!$updateResult) {
        throw new Exception('Ошибка обновления пользователя: ' . $user->LAST_ERROR);
    }
    
   // writeLog('User updated successfully');
    
    $response = [
        'success' => true,
        'message' => $agreement === 'Y' ? 'Согласие на рассылку сохранено' : 'Отказ от рассылки сохранен',
        'user_id' => $userID,
        'agreement' => $agreement
    ];
    
    // Обрабатываем список отказавшихся
    if ($agreement === 'N') {
        // Отказ от рассылки - добавляем в список
        $unsubscribeResult = addToUnsubscribedList($userID, $userData);
        $response['unsubscribe_record'] = $unsubscribeResult;
        writeLog('Added to unsubscribed list', $unsubscribeResult);
    } else {
        // Согласие на рассылку - удаляем из списка отказавшихся
        $removeResult = removeFromUnsubscribedList($userID, $userData);
        $response['remove_unsubscribe_record'] = $removeResult;
       // writeLog('Removed from unsubscribed list', $removeResult);
    }
    
    // Пытаемся обновить Bitrix24
    $response['contact_update'] = updateBitrix24Contact($userData, $agreement);
    
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    writeLog('Exception', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

// Функция добавления в список отказавшихся (поиск по EMAIL)
function addToUnsubscribedList($userID, $userData) {
    try {
        $iblockId = 69;
        $email = $userData['EMAIL'] ?? '';
        
        if (empty($email)) {
            return ['success' => false, 'error' => 'Email пользователя не найден'];
        }
        
       // writeLog('Starting add to unsubscribed by email', ['userID' => $userID, 'email' => $email]);
        
        // Сначала удаляем все существующие записи для этого email
        $cleanResult = removeFromUnsubscribedListByEmail($email);
        //writeLog('Cleaned old records by email before adding new', $cleanResult);
        
        // Добавляем новую запись
        $element = new CIBlockElement;
        $arFields = [
            'IBLOCK_ID' => $iblockId,
            'NAME' => 'Отказ от рассылки - ' . $email . ' (' . date('d.m.Y H:i') . ')',
            'ACTIVE' => 'Y',
            'CREATED_BY' => $userID,
            'PROPERTY_VALUES' => [
                'EMAIL' => $email,
                'USER_ID' => $userID,
                'DATE_CANCEL' => ConvertTimeStamp(time(), "FULL")
            ]
        ];
        
        writeLog('Adding new unsubscribe record by email', $arFields);
        
        $elementId = $element->Add($arFields);
        
        if ($elementId) {
            writeLog('Successfully added unsubscribe record', ['element_id' => $elementId, 'email' => $email]);
            
            // Проверяем, что запись действительно создалась
            $checkElement = CIBlockElement::GetByID($elementId)->Fetch();
            
            return [
                'success' => true,
                'element_id' => $elementId,
                'email' => $email,
                'message' => 'Запись об отказе добавлена для email: ' . $email . ' (ID: ' . $elementId . ')',
                'cleaned_old_records' => $cleanResult,
                'created_record' => $checkElement
            ];
        } else {
            $error = $element->LAST_ERROR ?: 'Неизвестная ошибка при добавлении';
            writeLog('Failed to add unsubscribe record', ['error' => $error, 'email' => $email]);
            return [
                'success' => false,
                'error' => 'Ошибка добавления записи для email ' . $email . ': ' . $error
            ];
        }
        
    } catch (Exception $e) {
        writeLog('Add to unsubscribed by email error', ['userID' => $userID, 'email' => $email ?? 'unknown', 'error' => $e->getMessage()]);
        return ['success' => false, 'error' => 'Ошибка работы с инфоблоком: ' . $e->getMessage()];
    }
}

// Функция удаления из списка отказавшихся по USER_ID (реальное удаление)
function removeFromUnsubscribedList($userID, $userData = null) {
    try {
        $iblockId = 69;
        
        // Сначала удаляем по USER_ID
        $elements = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_USER_ID' => $userID
            ],
            false,
            false,
            ['ID']
        );
        
        $deletedCount = 0;
        $element = new CIBlockElement;
        
        while ($elementData = $elements->Fetch()) {
            // Полное удаление элемента
            if (CIBlockElement::Delete($elementData['ID'])) {
                $deletedCount++;
              //  writeLog('Deleted unsubscribe record by USER_ID', ['element_id' => $elementData['ID']]);
            } else {
              //  writeLog('Failed to delete unsubscribe record by USER_ID', ['element_id' => $elementData['ID'], 'error' => 'Delete failed']);
            }
        }
        
        // Если есть userData, также удаляем по EMAIL
        if ($userData && !empty($userData['EMAIL'])) {
            $emailDeleted = removeFromUnsubscribedListByEmail($userData['EMAIL']);
         //   writeLog('Email-based cleanup result', $emailDeleted);
        }
        
        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'message' => $deletedCount > 0 ? "Удалено записей: $deletedCount" : "Записей не найдено"
        ];
        
    } catch (Exception $e) {
        writeLog('Remove from unsubscribed error', ['userID' => $userID, 'error' => $e->getMessage()]);
        return ['success' => false, 'error' => 'Ошибка удаления из списка отказавшихся: ' . $e->getMessage()];
    }
}

// Функция удаления из списка отказавшихся по EMAIL (реальное удаление)
function removeFromUnsubscribedListByEmail($email) {
    try {
        $iblockId = 69;
        
        if (empty($email)) {
            return ['success' => false, 'error' => 'Email не указан'];
        }
        
        $elements = CIBlockElement::GetList(
            [],
            [
                'IBLOCK_ID' => $iblockId,
                'PROPERTY_EMAIL' => $email
            ],
            false,
            false,
            ['ID']
        );
        
        $deletedCount = 0;
        $element = new CIBlockElement;
        
        while ($elementData = $elements->Fetch()) {
            // Полное удаление элемента
            if (CIBlockElement::Delete($elementData['ID'])) {
                $deletedCount++;
                writeLog('Deleted unsubscribe record by EMAIL', ['element_id' => $elementData['ID'], 'email' => $email]);
            } else {
                writeLog('Failed to delete unsubscribe record by EMAIL', ['element_id' => $elementData['ID'], 'email' => $email, 'error' => 'Delete failed']);
            }
        }
        
        return [
            'success' => true,
            'deleted_count' => $deletedCount,
            'message' => $deletedCount > 0 ? "Удалено записей по email: $deletedCount" : "Записей по email не найдено"
        ];
        
    } catch (Exception $e) {
        writeLog('Remove from unsubscribed by email error', ['email' => $email, 'error' => $e->getMessage()]);
        return ['success' => false, 'error' => 'Ошибка удаления из списка отказавшихся по email: ' . $e->getMessage()];
    }
}

// Функция обновления контакта в Bitrix24 через cURL
function updateBitrix24Contact($userData, $agreement) {
    try {
        // Получаем телефон
        $phone = '';
        if (!empty($userData['PERSONAL_PHONE'])) {
            $phone = trim($userData['PERSONAL_PHONE']);
        } elseif (!empty($userData['WORK_PHONE'])) {
            $phone = trim($userData['WORK_PHONE']);
        }
        
        if (empty($phone)) {
            return ['success' => false, 'error' => 'Телефон пользователя не найден'];
        }
        
        $domain = 'https://hypower.bitrix24.ru/rest/2595/jze5fzeehv3shx46/';
        
        // Ищем контакт по телефону
        $contactId = findContactByPhone($domain, $phone);
        
        if (!$contactId) {
            return ['success' => false, 'error' => 'Контакт не найден в Bitrix24'];
        }
        
       // writeLog('Found contact in Bitrix24', ['contact_id' => $contactId, 'phone' => $phone]);
        
        // Обновляем согласие на рассылку
        $updateResult = bitrix24ApiCall($domain, 'crm.contact.update', [
            'id' => $contactId,
            'fields' => [
                'UF_CRM_1756132435937' => $agreement // Правильное имя поля с Y/N значениями
            ]
        ]);
        
        if (isset($updateResult['result'])) {
            return [
                'success' => true, 
                'message' => 'Согласие на рассылку обновлено в Bitrix24', 
                'contact_id' => $contactId,
                'agreement' => $agreement
            ];
        } else {
            return [
                'success' => false, 
                'error' => 'Ошибка обновления контакта в Bitrix24', 
                'details' => $updateResult
            ];
        }
        
    } catch (Exception $e) {
        writeLog('Bitrix24 update error', ['error' => $e->getMessage()]);
        return ['success' => false, 'error' => 'Ошибка Bitrix24: ' . $e->getMessage()];
    }
}

// Функция поиска контакта по телефону через Bitrix24 API
function findContactByPhone($domain, $phone) {
    try {
        // Нормализуем телефон и генерируем варианты
        $phoneVariants = generatePhoneVariants(normalizePhoneForBitrix24($phone));
        
       // writeLog('Searching contact by phone variants', ['phone' => $phone, 'variants' => $phoneVariants]);
        
        // Ищем по каждому варианту
        foreach ($phoneVariants as $phoneVariant) {
            $result = bitrix24ApiCall($domain, 'crm.contact.list', [
                'filter' => ['PHONE' => $phoneVariant],
                'select' => ['ID'],
                'order' => ['ID' => 'DESC']
            ]);
            
            if (isset($result['result']) && !empty($result['result'])) {
                writeLog('Contact found by phone', ['contact_id' => $result['result'][0]['ID'], 'phone_variant' => $phoneVariant]);
                return $result['result'][0]['ID'];
            }
        }
        
       // writeLog('Contact not found by phone', ['phone' => $phone]);
        return null;
        
    } catch (Exception $e) {
        writeLog('Find contact by phone error', ['error' => $e->getMessage()]);
        return null;
    }
}

// Универсальная функция для вызова Bitrix24 REST API через cURL
function bitrix24ApiCall($domain, $method, $params = []) {
    try {
        $url = $domain . $method;
        
        // Подготавливаем POST данные
        $postData = http_build_query($params);
        
        // Инициализируем cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        
        // Выполняем запрос
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        
        if (!empty($error)) {
            writeLog('Bitrix24 API cURL error', ['error' => $error]);
            return ['error' => 'cURL error: ' . $error];
        }
        
        if ($httpCode !== 200) {
            writeLog('Bitrix24 API HTTP error', ['http_code' => $httpCode]);
            return ['error' => 'HTTP error: ' . $httpCode];
        }
        
        $result = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            writeLog('Bitrix24 API JSON decode error', ['response' => $response]);
            return ['error' => 'JSON decode error'];
        }
        
       // writeLog('Bitrix24 API response', ['method' => $method, 'result' => $result]);
        
        return $result;
        
    } catch (Exception $e) {
        writeLog('Bitrix24 API call error', ['method' => $method, 'error' => $e->getMessage()]);
        return ['error' => $e->getMessage()];
    }
}

// Нормализация телефона
function normalizePhoneForBitrix24($phone) {
    $phone = preg_replace('/[^\d+]/', '', $phone);
    
    if (preg_match('/^8(\d{10})$/', $phone, $matches)) {
        $phone = '+7' . $matches[1];
    }
    
    if (preg_match('/^7(\d{10})$/', $phone)) {
        $phone = '+' . $phone;
    }
    
    return $phone;
}

// Генерация вариантов телефона
function generatePhoneVariants($normalizedPhone) {
    $phoneVariants = [];
    $cleanPhone = preg_replace('/[^\d]/', '', $normalizedPhone);
    
    if (strlen($cleanPhone) >= 10) {
        $last10digits = substr($cleanPhone, -10);
        $phoneVariants[] = $normalizedPhone;
        $phoneVariants[] = '8' . $last10digits;
        $phoneVariants[] = '7' . $last10digits;
        $phoneVariants[] = $cleanPhone;
    }
    
    return array_unique($phoneVariants);
}
?>