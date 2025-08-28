<?php

class Bitrix24ContactManager
{
    private $webhook_url;
    
    public function __construct($webhook_url)
    {
        $this->webhook_url = rtrim($webhook_url, '/') . '/';
    }
    
    /**
     * Обновляет согласие на рассылку у контакта в Bitrix24
     */
    public function updateNewsletterAgreement($phone, $agreement)
    {
        try {
            $normalizedPhone = $this->normalizePhoneForBitrix24($phone);
            
            // Генерируем различные варианты номера для поиска
            $phoneVariants = $this->generatePhoneVariants($normalizedPhone);
            
            $contactId = $this->findContactByPhone($phoneVariants);
            
            if (!$contactId) {
                return [
                    'success' => false,
                    'error' => 'Контакт не найден по всем вариантам номера',
                    'tried_variants' => $phoneVariants
                ];
            }
            
            // Обновление контакта
            $updateResult = $this->makeRestRequest('crm.contact.update.json', [
                'id' => $contactId,
                'fields' => ['UF_CRM_1756132435937' => $agreement]
            ]);
            
            if (isset($updateResult['error'])) {
                return [
                    'success' => false,
                    'error' => 'Ошибка обновления: ' . $updateResult['error']
                ];
            }
            
            return [
                'success' => true,
                'contact_id' => $contactId,
                'message' => 'Контакт обновлен',
                'phone' => $normalizedPhone,
                'tried_variants' => $phoneVariants
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Поиск контакта по различным вариантам номера телефона
     */
    private function findContactByPhone($phoneVariants)
    {
        foreach ($phoneVariants as $phoneVariant) {
            $searchResult = $this->makeRestRequest('crm.contact.list.json', [
                'filter' => ['PHONE' => $phoneVariant],
                'select' => ['ID', 'NAME', 'LAST_NAME', 'PHONE']
            ]);
            
            if (isset($searchResult['error'])) {
                continue;
            }
            
            if (!empty($searchResult['result'])) {
                return $searchResult['result'][0]['ID'];
            }
        }
        
        return null;
    }
    
    /**
     * Генерирует различные варианты номера телефона для поиска
     */
    private function generatePhoneVariants($normalizedPhone)
    {
        $phoneVariants = [];
        $cleanPhone = preg_replace('/[^\d]/', '', $normalizedPhone);
        
        if (strlen($cleanPhone) >= 10) {
            $last10digits = substr($cleanPhone, -10);
            
            $phoneVariants[] = $normalizedPhone; // +79935845697
            $phoneVariants[] = '8' . $last10digits; // 89935845697
            $phoneVariants[] = '7' . $last10digits; // 79935845697
            $phoneVariants[] = '+7 ' . substr($last10digits, 0, 3) . ' ' . substr($last10digits, 3, 3) . '-' . substr($last10digits, 6, 2) . '-' . substr($last10digits, 8, 2); // +7 993 584-56-97
            $phoneVariants[] = '8 ' . substr($last10digits, 0, 3) . ' ' . substr($last10digits, 3, 3) . '-' . substr($last10digits, 6, 2) . '-' . substr($last10digits, 8, 2); // 8 993 584-56-97
            $phoneVariants[] = '7 ' . substr($last10digits, 0, 3) . ' ' . substr($last10digits, 3, 3) . '-' . substr($last10digits, 6, 2) . '-' . substr($last10digits, 8, 2); // 7 993 584-56-97
        }
        
        return $phoneVariants;
    }
    
    /**
     * Нормализует номер телефона
     */
    private function normalizePhoneForBitrix24($phone)
    {
        $phone = preg_replace('/[^\d+]/', '', $phone);
        
        if (preg_match('/^8(\d{10})$/', $phone, $matches)) {
            $phone = '+7' . $matches[1];
        }
        
        if (preg_match('/^7(\d{10})$/', $phone)) {
            $phone = '+' . $phone;
        }
        
        return $phone;
    }
    
    /**
     * Выполняет REST запрос к Bitrix24
     */
    private function makeRestRequest($method, $params = [])
    {
        $url = $this->webhook_url . $method;
        
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            curl_close($ch);
            return ['error' => 'Ошибка cURL: ' . curl_error($ch)];
        }
        
        curl_close($ch);
        
        $decodedResponse = json_decode($response, true);
        
        if ($httpCode !== 200) {
            return ['error' => 'HTTP Error: ' . $httpCode];
        }
        
        return $decodedResponse;
    }
}
?>