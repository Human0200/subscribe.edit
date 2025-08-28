<?php
require($_SERVER["DOCUMENT_ROOT"]."/bitrix/modules/main/include/prolog_before.php");
require_once(__DIR__.'/Bitrix24ContactManager.php');

// Подключаем модуль инфоблоков
if (!CModule::IncludeModule("iblock")) {
    http_response_code(500);
    echo json_encode(['error' => 'Модуль инфоблоков не доступен']);
    exit;
}

// Логируем входящие данные
file_put_contents(__DIR__.'/debug.txt', date('Y-m-d H:i:s') . ' - POST data: ' . json_encode($_POST) . "\n", FILE_APPEND);

if (!$USER->IsAuthorized()) {
    http_response_code(403);
    echo json_encode(['error' => 'Пользователь не авторизован']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Метод не поддерживается']);
    exit;
}

$userID = $USER->GetID();
$agreement = ($_POST['agreement'] === 'Y') ? '1' : '0';

// Получаем данные пользователя
$userObj = new CUser;
$userData = $userObj->GetByID($userID)->Fetch();

if (!$userData) {
    http_response_code(404);
    echo json_encode(['error' => 'Пользователь не найден']);
    exit;
}

// Получаем номер телефона пользователя
$phone = '';
if (!empty($userData['PERSONAL_PHONE'])) {
    $phone = trim($userData['PERSONAL_PHONE']);
} elseif (!empty($userData['WORK_PHONE'])) {
    $phone = trim($userData['WORK_PHONE']);
}

// Обновляем пользователя
$user = new CUser;
$userUpdateResult = $user->Update($userID, ['UF_NEWSLETTER_AGREEMENT' => $agreement]);

$response = [
    'user_update' => [
        'success' => $userUpdateResult,
        'user_id' => $userID,
        'agreement' => $agreement,
        'phone_found' => !empty($phone) ? $phone : 'Телефон не найден'
    ],
    'contact_update' => null
];

if (!$userUpdateResult) {
    $response['user_update']['error'] = 'Ошибка сохранения пользователя: ' . $user->LAST_ERROR;
}

// Если найден номер телефона, обновляем контакт в Bitrix24
if (!empty($phone)) {
    $bitrix24Manager = new Bitrix24ContactManager('https://hypower.bitrix24.ru/rest/2595/jze5fzeehv3shx46/');
    $contactUpdateResult = $bitrix24Manager->updateNewsletterAgreement($phone, $agreement);
    
    $response['contact_update'] = $contactUpdateResult;
} else {
    $response['contact_update'] = [
        'success' => false,
        'error' => 'Номер телефона пользователя не найден'
    ];
}

// Если пользователь отказался от рассылки, добавляем запись в инфоблок
if ($agreement === '0') {
    $unsubscribeResult = addToUnsubscribedList($userID, $userData);
    $response['unsubscribe_record'] = $unsubscribeResult;
}

// Формируем итоговый ответ
$finalResponse = [
    'success' => $response['user_update']['success'],
    'message' => $response['user_update']['success'] ? 'Пользователь успешно обновлен' : 'Ошибка обновления пользователя',
    'user_update' => $response['user_update'],
    'contact_update' => $response['contact_update']
];

echo json_encode($finalResponse);

// Функция добавления записи в инфоблок отказавшихся
function addToUnsubscribedList($userID, $userData) {
    // ID инфоблока "Отказавшиеся" (создается скриптом выше)
    $iblockId = getUnsubscribedIblockId();
    
    if (!$iblockId) {
        return [
            'success' => false,
            'error' => 'Инфоблок "Отказавшиеся" не найден'
        ];
    }
    
    $email = $userData['EMAIL'] ?? '';
    if (empty($email)) {
        return [
            'success' => false,
            'error' => 'Email пользователя не найден'
        ];
    }
    
    // Проверяем, нет ли уже записи для этого пользователя
    $existingElement = CIBlockElement::GetList(
        [],
        [
            'IBLOCK_ID' => $iblockId,
            'PROPERTY_USER_ID' => $userID,
            'ACTIVE' => 'Y'
        ],
        false,
        false,
        ['ID']
    );
    
    if ($existingElement->Fetch()) {
        $element = new CIBlockElement;
            $arFields = [
        'IBLOCK_ID' => $iblockId,
        'NAME' => 'Отказ от рассылки - ' . $email,
        'ACTIVE' => 'Y',
        'DATE_CREATE' => date('Y.m.d H:i:s'),
        'CREATED_BY' => $userID,
        'PROPERTY_VALUES' => [
            'EMAIL' => $email,
            'USER_ID' => $userID,
            'DATE_CANCEL' => date('Y.m.d H:i:s')
        ]
    ];
    
    $elementId = $element->Update($existingElement->Fetch()['ID'], $arFields);
            return [
            'success' => true,
            'message' => 'Запись уже существует, обновляем ее'
        ];
    }
    
    // Добавляем новую запись
    $element = new CIBlockElement;
    $arFields = [
        'IBLOCK_ID' => $iblockId,
        'NAME' => 'Отказ от рассылки - ' . $email,
        'ACTIVE' => 'Y',
        'DATE_CREATE' => date('Y.m.d H:i:s'),
        'CREATED_BY' => $userID,
        'PROPERTY_VALUES' => [
            'EMAIL' => $email,
            'USER_ID' => $userID,
            'DATE_CANCEL' => date('Y.m.d H:i:s')
        ]
    ];
    
    $elementId = $element->Add($arFields);
    
    if ($elementId) {
        return [
            'success' => true,
            'element_id' => $elementId,
            'message' => 'Запись об отказе добавлена'
        ];
    } else {
        return [
            'success' => false,
            'error' => 'Ошибка добавления записи: ' . $element->LAST_ERROR
        ];
    }
}

// Функция получения ID инфоблока отказавшихся
function getUnsubscribedIblockId() {
    static $iblockId = 69;
    
    if ($iblockId === null) {
        $iblock = CIBlock::GetList(
            [],
            [
                'CODE' => 'UNSUBSCRIBED_USERS',
                'ACTIVE' => 'Y'
            ]
        );
        
        if ($arIblock = $iblock->Fetch()) {
            $iblockId = $arIblock['ID'];
        } else {
            $iblockId = false;
        }
    }
    
    return $iblockId;
}
?>