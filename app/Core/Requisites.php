<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Данные оператора персональных данных (v4.9, переписано в v4.10).
 *
 * Первый заход вышел под российское юрлицо: ИНН, ОГРН, КПП, юридический и
 * почтовый адрес по отдельности, должность руководителя. Для установки в любой
 * другой стране половина полей не существует, а нужного нет: у европейской
 * компании спросят VAT и представителя по GDPR, у американской — EIN, и ни у
 * кого — КПП.
 *
 * Поэтому поля названы по смыслу, а не по местному бланку: «налоговый номер»
 * вместо ИНН, «регистрационный номер» вместо ОГРН, один адрес вместо двух.
 * Формат не навязывается — подпись объясняет, что вписать в своей стране.
 *
 * Список короткий намеренно. Документ требует ответить на четыре вопроса: кто
 * оператор, где он, куда писать и кто отвечает за обработку. Всё остальное
 * владелец допишет в текст документа сам — и допишет один раз, а не будет
 * заполнять двенадцать полей, из которых у него есть три.
 */
final class Requisites
{
    /**
     * @return list<array{key:string,label:string,type:string,mask:?string,
     *                    hint:string,placeholder:string,max:int,required:bool}>
     */
    public static function fields(): array
    {
        return [
            ['key' => 'operator', 'label' => 'Оператор данных', 'type' => 'text',
             'mask' => null, 'max' => 200, 'required' => true,
             'placeholder' => 'Acme Inc. / ООО «Ромашка» / Иван Иванов',
             'hint' => 'Кто отвечает за обработку: компания или человек. Так, как в учредительных документах'],
            ['key' => 'country', 'label' => 'Страна', 'type' => 'text',
             'mask' => null, 'max' => 80, 'required' => true,
             'placeholder' => 'Россия / Serbia / Germany',
             'hint' => 'По её праву работает документ — от этого зависит, что в нём обязано быть'],
            ['key' => 'address', 'label' => 'Адрес', 'type' => 'textarea',
             'mask' => null, 'max' => 300, 'required' => false,
             'placeholder' => '101000, Москва, ул. Примерная, 1',
             'hint' => 'Один адрес — тот, по которому оператора находят официально'],
            ['key' => 'tax_id', 'label' => 'Налоговый номер', 'type' => 'text',
             'mask' => null, 'max' => 40, 'required' => false,
             'placeholder' => 'ИНН · VAT · EIN · Tax ID',
             'hint' => 'Как он называется в вашей стране — так и вписывайте. Формат не проверяем: у каждой страны свой'],
            ['key' => 'reg_id', 'label' => 'Регистрационный номер', 'type' => 'text',
             'mask' => null, 'max' => 40, 'required' => false,
             'placeholder' => 'ОГРН · Company number · Registration no.',
             'hint' => 'Номер записи о регистрации, если он у вас есть'],
            ['key' => 'email', 'label' => 'Почта для обращений', 'type' => 'email',
             'mask' => null, 'max' => 190, 'required' => true,
             'placeholder' => 'privacy@example.com',
             'hint' => 'Куда пишут по поводу данных. Отдельный адрес удобнее личного: его не жалко публиковать'],
            ['key' => 'phone', 'label' => 'Телефон', 'type' => 'phone',
             'mask' => null, 'max' => 32, 'required' => false,
             'placeholder' => '+7 900 000-00-00',
             'hint' => 'С кодом страны. Необязателен, если хватает почты'],
            ['key' => 'website', 'label' => 'Сайт', 'type' => 'url',
             'mask' => null, 'max' => 190, 'required' => false,
             'placeholder' => 'https://example.com',
             'hint' => 'Основной сайт оператора, если он отличается от адреса этой установки'],
            ['key' => 'dpo', 'label' => 'Ответственный за обработку', 'type' => 'text',
             'mask' => null, 'max' => 190, 'required' => false,
             'placeholder' => 'Имя или подразделение',
             'hint' => 'В ЕС это обязательный DPO, в других странах — по желанию. Пусто — и в документе строки не будет'],
        ];
    }

    public static function get(string $key): string
    {
        return (string)Setting::get('req.' . $key, '');
    }

    /** Сохранить всё разом: полей меньше десятка, и правят их целиком. */
    public static function saveFromPost(array $post): void
    {
        foreach (self::fields() as $f) {
            $v = trim((string)($post['req_' . $f['key']] ?? ''));
            /* Телефон чистим до «плюс и цифры»: человек копирует его из письма
               вместе со скобками и неразрывными пробелами, а в документ это
               уедет как есть. Формат при этом не навязываем — код страны у
               всех свой, и «правильных» скобок не существует. */
            if ($f['type'] === 'phone' && $v !== '') {
                $plus = str_starts_with($v, '+');
                $v = ($plus ? '+' : '') . preg_replace('~\D+~u', '', $v);
            }
            $v = mb_substr($v, 0, $f['max']);
            if ($v === '') Setting::delete('req.' . $f['key']);
            else Setting::set('req.' . $f['key'], $v);
        }
    }

    /**
     * Подстановки для документов: `{{ Оператор данных }}` и остальные.
     *
     * Имена по-русски намеренно: документ пишет и правит не программист, и
     * `{{ operator }}` посреди «Политики конфиденциальности» он не наберёт ни
     * разу, а `{{ Оператор данных }}` — наберёт.
     *
     * @return array<string,string>
     */
    public static function placeholders(): array
    {
        $out = [];
        foreach (self::fields() as $f) {
            $out['{{ ' . $f['label'] . ' }}'] = self::get($f['key']);
        }
        return $out;
    }

    public static function filled(): int
    {
        $n = 0;
        foreach (self::fields() as $f) if (self::get($f['key']) !== '') $n++;
        return $n;
    }

    /**
     * Чего не хватает для того, чтобы документ был документом.
     *
     * Не всё подряд: обязательны те четыре ответа, без которых «Политика
     * конфиденциальности» перестаёт быть политикой — кто оператор, по праву
     * какой страны, куда писать. Остальное — по обстоятельствам.
     *
     * @return list<string> подписи незаполненных обязательных полей
     */
    public static function missing(): array
    {
        $out = [];
        foreach (self::fields() as $f) {
            if ($f['required'] && self::get($f['key']) === '') $out[] = t($f['label']);
        }
        return $out;
    }
}
