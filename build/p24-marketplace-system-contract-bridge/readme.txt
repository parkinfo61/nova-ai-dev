=== P24 Marketplace OS — System Contract Bridge ===
Contributors: nova
Tags: marketplace, audit, routes, categories, wordpress
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.0-alpha.3.5.2
License: Proprietary

Единый машинный паспорт Маркета, реестр маршрутов и безопасная семантика рубрик.

== Description ==

Полевой мост для P24 Marketplace OS 1.0.0-alpha.3.5.1 и выше.

Возможности:

* защищённый REST-паспорт /wp-json/p24-market/v1/system-map;
* реестр сущностей, маршрутов, ролей и действий;
* автоматический выбор тестового объявления и рубрики;
* контрольные meta-маркеры страниц для NOVA Web Audit;
* отдельные состояния для транспорта, одежды, недвижимости и услуг;
* подтверждение смены типа объявления;
* очистка только несовместимых характеристик после подтверждения;
* безопасное исправление публичной семантики «Требует ремонта» для одежды;
* страница «Карта системы» в контуре Маркета.

Плагин не создаёт таблицы, не меняет платежи, PIN-вход, сообщения и существующие роли.

== Installation ==

1. Сделайте резервную копию файлов P24 Marketplace OS и базы данных.
2. Убедитесь, что активно ядро P24 Marketplace OS версии 1.0.0-alpha.3.5.1 или новее.
3. WordPress → Плагины → Добавить плагин → Загрузить плагин.
4. Выберите p24-marketplace-os-1.0.0-alpha.3.5.2-system-contract-bridge.zip.
5. Активируйте плагин.
6. Откройте «Шахты24 Маркет → Карта системы».
7. Проверьте JSON-паспорт и тестовые маршруты.

== Changelog ==

= 1.0.0-alpha.3.5.2 =

* System Contract v1.
* Entity, route, role and action registries.
* Automatic test-object selection.
* Category-specific condition dictionaries.
* Safe category reclassification guard.
* Protected REST machine passport.

== Upgrade Notice ==

Полевой мост устанавливается рядом с рабочим ядром. После подтверждения логики его код можно перенести внутрь следующей полной сборки P24 Marketplace OS.
