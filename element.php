<?php
// Блок: корректное применение SEO-шаблонов инфоблока к странице (использует D7 InheritedProperty)
use Bitrix\Main\Loader;

if (!defined("B_PROLOG_INCLUDED") || B_PROLOG_INCLUDED !== true) {
    return;
}

// Определяем iblock и element по надежной цепочке источников
$iblockId = isset($arResult['IBLOCK_ID']) && $arResult['IBLOCK_ID'] ? $arResult['IBLOCK_ID'] : ($arParams['IBLOCK_ID'] ?? ($arResult['SECTION']['IBLOCK_ID'] ?? null));
$elementData = $arResult['ELEMENT'] ?? $arResult['ELEMENT'] ?? null;
$elementId = $elementData['ID'] ?? ($arResult['ID'] ?? ($arParams['ELEMENT_ID'] ?? null));
$sectionId = $arResult['SECTION']['ID'] ?? ($arParams['SECTION_ID'] ?? null);

if ($iblockId && Loader::includeModule('iblock')) {
    try {
        // Попытка получить SEO для элемента (если есть elementId)
        if ($elementId && class_exists('\Bitrix\Iblock\InheritedProperty\ElementValues')) {
            $ev = new \Bitrix\Iblock\InheritedProperty\ElementValues((int)$iblockId, (int)$elementId);
            $iprop = $ev->getValues();

           
            // после получения $iprop и $iprop['ELEMENT_META_TITLE']
            if (!empty($iprop['ELEMENT_META_TITLE'])) {
                $APPLICATION->SetTitle($iprop['ELEMENT_META_TITLE']);
                // Запишем и в page property — это позволит header отдавать именно SEO‑заголовок
                $APPLICATION->SetPageProperty('title', $iprop['ELEMENT_META_TITLE']);
            }
            if (!empty($iprop['ELEMENT_META_DESCRIPTION'])) {
                $APPLICATION->SetPageProperty('description', $iprop['ELEMENT_META_DESCRIPTION']);
            }
           
        } else {
            // Фоллбек: если нет elementId — пробуем SectionValues (для страниц раздела)
            if ($sectionId && class_exists('\Bitrix\Iblock\InheritedProperty\SectionValues')) {
                $sv = new \Bitrix\Iblock\InheritedProperty\SectionValues((int)$iblockId, (int)$sectionId);
                $sprop = $sv->getValues();

                if (!empty($sprop['SECTION_META_TITLE'])) {
                    $APPLICATION->SetTitle($sprop['SECTION_META_TITLE']);
                }
                if (!empty($sprop['SECTION_META_DESCRIPTION'])) {
                    $APPLICATION->SetPageProperty('description', $sprop['SECTION_META_DESCRIPTION']);
                }
            }
        }
    } catch (\Throwable $e) {
        // Логируем в /upload на случай ошибок (удалите в проде)
        @file_put_contents($_SERVER['DOCUMENT_ROOT'] . '/upload/seo_apply_error_' . time() . '.log', $e->getMessage() . "\n" . print_r([
            'iblockId' => $iblockId,
            'elementId' => $elementId,
            'sectionId' => $sectionId
        ], true));
    }
}



/** @var array $arParams */
/** @var array $arResult */
/** @global CMain $APPLICATION */

$element = $arResult["ELEMENT"];
$actualItem = $element;
$alt = $element['NAME'];
$section = $arResult["SECTION"] ?? null;
$name = $element['NAME'];
global $USER;

$arDiscounts = CCatalogDiscount::GetDiscountByProduct(
    $element['ID'],
    $USER->GetUserGroupArray(),
    'N',
    array(),
    SITE_ID
);

$optimalPrice = CCatalogProduct::GetOptimalPrice($element['ID'], 1, $USER->GetUserGroupArray(), 'N', array(), SITE_ID);
if ($optimalPrice['RESULT_PRICE']) {
    $currentPrice = $optimalPrice['RESULT_PRICE']['PRICE'];
    $basePrice = $optimalPrice['RESULT_PRICE']['BASE_PRICE'];
    $currency = $optimalPrice['RESULT_PRICE']['CURRENCY'];
    if ($currentPrice == 0 && $basePrice > 0) {
        foreach ($arDiscounts as $discount) {
            if ($discount['VALUE_TYPE'] == 'P') {
                $discountPercent = $discount['VALUE'];
                $currentPrice = $basePrice * (1 - $discountPercent / 100);
                break;
            }
        }
        if ($currentPrice == 0) {
            $currentPrice = $basePrice;
        }
    }
} else {
    $currentPrice = 0;
    $basePrice = 0;
    $currency = 'RUB';
}

$price = [
    'PRICE' => $currentPrice,
    'BASE_PRICE' => $basePrice,
    'PRINT_RATIO_PRICE' => number_format($currentPrice, 0, '.', ' ') . ' ₽',
    'PRINT_RATIO_BASE_PRICE' => number_format($basePrice, 0, '.', ' ') . ' ₽',
    'CURRENCY' => $currency,
    'MIN_QUANTITY' => 1
];

$itemIds = [
    'ID' => $element['ID'],
    'QUANTITY_ID' => $element['ID'] . '_quantity',
    'ADD_BASKET_LINK' => $element['ID'] . '_add_basket',
    'COMPARE_LINK' => $element['ID'] . '_compare',
    'BUY_LINK' => $element['ID'] . '_buy',
    'PRICE_ID' => $element['ID'] . '_price',
    'OLD_PRICE_ID' => $element['ID'] . '_old_price',
    'DISCOUNT_PRICE_ID' => $element['ID'] . '_discount_price',
];

// Fetch all properties for the element
$arSelect = Array("ID", "IBLOCK_ID", "NAME", "DETAIL_TEXT", "PROPERTY_*");
$arFilter = Array("ID" => $element['ID']);
$res = CIBlockElement::GetList(Array(), $arFilter, false, false, $arSelect);
$arProps = array();
if ($ob = $res->GetNextElement()) {
    $fields = $ob->GetFields();
    $arResult['DETAIL_TEXT'] = $fields['DETAIL_TEXT']; // Для вывода описания
    $arProps = $ob->GetProperties();
}
$arResult['PROPERTIES'] = $arProps;

// Получение названия коллекции
$collectionNames = [];
if (is_array($arProps['COLLECTIONS']['VALUE'])) {
    foreach ($arProps['COLLECTIONS']['VALUE'] as $id) {
        $res = CIBlockElement::GetByID($id);
        if ($arElement = $res->GetNext()) {
            $collectionNames[] = $arElement['NAME'];
        }
    }
} elseif ($arProps['COLLECTIONS']['VALUE']) {
    $res = CIBlockElement::GetByID($arProps['COLLECTIONS']['VALUE']);
    if ($arElement = $res->GetNext()) {
        $collectionNames[] = $arElement['NAME'];
    }
}
$collectionName = implode(', ', $collectionNames);

// Получение URL коллекции
$collectionUrl = '#';
if (!empty($arProps['COLLECTIONS']['VALUE'])) {
    $collectionId = is_array($arProps['COLLECTIONS']['VALUE']) ? $arProps['COLLECTIONS']['VALUE'][0] : $arProps['COLLECTIONS']['VALUE'];
    $res = CIBlockElement::GetByID($collectionId);
    if ($arElement = $res->GetNext()) {
        $collectionUrl = $arElement['DETAIL_PAGE_URL'];
    }
}



// Хлебные крошки
$APPLICATION->AddChainItem("Каталог", "/catalog/");
if ($section) {
    $APPLICATION->AddChainItem($section['NAME'], $section['SECTION_PAGE_URL']);
}
$APPLICATION->AddChainItem($element['NAME']);

// Fetch collection products
$collectionProducts = array();
if (!empty($arProps['COLLECTIONS']['VALUE'])) {
    $collectionIds = is_array($arProps['COLLECTIONS']['VALUE']) ? $arProps['COLLECTIONS']['VALUE'] : array($arProps['COLLECTIONS']['VALUE']);
    $arFilter = array(
        "IBLOCK_ID" => 12,
        "!ID" => $element['ID'],
        "PROPERTY_COLLECTIONS" => $collectionIds,
        "ACTIVE" => "Y"
    );
    $arSelect = array("ID", "NAME", "DETAIL_PAGE_URL", "PREVIEW_PICTURE");
    $res = CIBlockElement::GetList(array("SORT" => "ASC"), $arFilter, false, array("nTopCount" => 6), $arSelect);
    while ($arItem = $res->GetNext()) {
        $arItem['PREVIEW_PICTURE_SRC'] = CFile::GetPath($arItem['PREVIEW_PICTURE']); // Обработка картинки
        $optimalPrice = CCatalogProduct::GetOptimalPrice($arItem['ID'], 1, $USER->GetUserGroupArray(), 'N', array(), SITE_ID);
        $arItem['PRICE'] = $optimalPrice['RESULT_PRICE']['PRICE'] ?? 0;
        $collectionProducts[] = $arItem;
    }
}

// Fetch similar products
$similarProducts = array();
if ($section && !empty($section['ID'])) {
    $arFilter = array(
        "IBLOCK_ID" => $arResult['IBLOCK_ID'],
        "SECTION_ID" => $section['ID'],
        "!ID" => $element['ID'],
        "ACTIVE" => "Y"
    );
    $arSelect = array("ID", "NAME", "DETAIL_PAGE_URL", "PREVIEW_PICTURE");
    $res = CIBlockElement::GetList(array("SORT" => "ASC"), $arFilter, false, array("nTopCount" => 6), $arSelect);
    while ($arItem = $res->GetNext()) {
        $arItem['PREVIEW_PICTURE_SRC'] = CFile::GetPath($arItem['PREVIEW_PICTURE']); // Обработка картинки
        $optimalPrice = CCatalogProduct::GetOptimalPrice($arItem['ID'], 1, $USER->GetUserGroupArray(), 'N', array(), SITE_ID);
        $arItem['PRICE'] = $optimalPrice['RESULT_PRICE']['PRICE'] ?? 0;
        $similarProducts[] = $arItem;
    }
}
?>
    <link rel="stylesheet" href="<?=SITE_TEMPLATE_PATH;?>/css/drift-basic.min.css">
    <link rel="stylesheet" href="<?=SITE_TEMPLATE_PATH;?>/css/photoswipe.css">
<?
// Получение предыдущего и следующего товара в разделе
$prevProduct = null;
$nextProduct = null;
if ($section && !empty($section['ID'])) {
    $rsElements = CIBlockElement::GetList(
        array("SORT" => "ASC", "NAME" => "ASC"),
        array(
            "IBLOCK_ID" => $arResult['IBLOCK_ID'],
            "SECTION_ID" => $section['ID'],
            "ACTIVE" => "Y",
            "INCLUDE_SUBSECTIONS" => "Y"
        ),
        false,
        false,
        array("ID", "NAME", "DETAIL_PAGE_URL")
    );

    $elements = array();
    while ($arElement = $rsElements->GetNext()) {
        $elements[] = $arElement;
    }

    $currentIndex = -1;
    foreach ($elements as $index => $elem) {
        if ($elem['ID'] == $element['ID']) {
            $currentIndex = $index;
            break;
        }
    }

    if ($currentIndex > 0) {
        $prevProduct = $elements[$currentIndex - 1];
    }
    if ($currentIndex >= 0 && $currentIndex < count($elements) - 1) {
        $nextProduct = $elements[$currentIndex + 1];
    }
}
?>

<div class="tf-breadcrumb">
            <div class="container">
                <div class="tf-breadcrumb-wrap">
                    <div class="tf-breadcrumb-list">
                        <a href="/" class="text text-caption-1">Главная</a>
                        <i class="icon icon-right"></i>
                        <a href="/catalog/" class="text text-caption-1">Каталог</a>
                        <i class="icon icon-right"></i>
                        <a href="<?=$arResult['SECTION']['SECTION_PAGE_URL'] ?? '#'?>" class="text text-caption-1"><?=$section['NAME'] ?? '#'?></a>
                        <i class="icon icon-right"></i>
                        <span class="text_secondary2 text-caption-1"><?=$name?></span>
                    </div>
					<div class="tf-breadcrumb-prev-next">
					<? //echo "<!-- DEBUG SECTION: " . ($section ? $section['ID'] : 'NO SECTION') . " -->"; ?>
    <?php if ($prevProduct): ?>
        <a href="<?=$prevProduct['DETAIL_PAGE_URL']?>" class="tf-breadcrumb-prev" title="<?=$prevProduct['NAME']?>">
            <i class="icon icon-left"></i>
        </a>
    <?php else: ?>
        <span class="tf-breadcrumb-prev disabled">
            <i class="icon icon-left"></i>
        </span>
    <?php endif; ?>
    <a href="<?=$arResult['SECTION']['SECTION_PAGE_URL'] ?? '#'?>" class="tf-breadcrumb-back">
        <i class="icon icon-squaresfour"></i>
    </a>
    <?php if ($nextProduct): ?>
        <a href="<?=$nextProduct['DETAIL_PAGE_URL']?>" class="tf-breadcrumb-next" title="<?=$nextProduct['NAME']?>">
            <i class="icon icon-right"></i>
        </a>
    <?php else: ?>
        <span class="tf-breadcrumb-next disabled">
            <i class="icon icon-right"></i>
        </span>
    <?php endif; ?>
</div>

                </div>
            </div>
        </div>


		<!-- /.tf-breadcrumb -->
<?
// Получение фото из свойства PHOTO_GALLERY
$galleryPhotos = array();
if (!empty($arResult['PROPERTIES']['PHOTO_GALLERY']['VALUE'])) {
    foreach ($arResult['PROPERTIES']['PHOTO_GALLERY']['VALUE'] as $fileId) {
        $filePath = CFile::GetPath($fileId);
        if ($filePath) {
            $galleryPhotos[] = array(
                'SRC' => $filePath,
                'ALT' => $alt // Используем общий alt из $arResult
            );
        }
    }
} elseif (!empty($actualItem['MORE_PHOTO'])) {
    // Fallback на MORE_PHOTO, если PHOTO_GALLERY пустое
    $galleryPhotos = $actualItem['MORE_PHOTO'];
}
?>
	<!-- Section product -->
	<section class="flat-spacing">
		<div class="container">
			<div class="row">
				<div class="col-md-6">
    <div class="tf-product-media-wrap sticky-top">
        <div class="thumbs-slider">
            <div dir="ltr" class="swiper tf-product-media-thumbs other-image-zoom" data-direction="vertical">
                <div class="swiper-wrapper stagger-wrap">
                    <?php
                    if (!empty($galleryPhotos))
                    {
                        foreach ($galleryPhotos as $key => $photo)
                        {
                            ?>
                            <div class="swiper-slide stagger-item" data-color="beige">
                                <div class="item">
                                    <img class="lazyload" data-src="<?=$photo['SRC']?>" src="<?=$photo['SRC']?>" alt="<?=$photo['ALT']?>">
                                </div>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
            <div dir="ltr" class="swiper tf-product-media-main" id="gallery-swiper-started">
                <div class="swiper-wrapper">
                    <?php
                    if (!empty($galleryPhotos))
                    {
                        foreach ($galleryPhotos as $key => $photo)
                        {
                            ?>
                            <div class="swiper-slide" data-color="gray">
                                <a href="<?=$photo['SRC']?>" target="_blank" class="item" data-pswp-width="600px" data-pswp-height="600px">
                                    <img class="tf-image-zoom lazyload" data-zoom="<?=$photo['SRC']?>" data-src="<?=$photo['SRC']?>" src="<?=$photo['SRC']?>" alt="<?=$photo['ALT']?>">
                                </a>
                            </div>
                            <?php
                        }
                    }
                    ?>
                </div>
            </div>
        </div>
    </div>
</div>
				<div class="col-md-6">
					<div class="sticky-top">
						<div class="tf-product-info-wrap position-relative">
							<div class="tf-zoom-main"></div>
							<div class="tf-product-info-list other-image-zoom">
								<div class="tf-product-info-heading">
									<div class="tf-product-info-name">
										<h3 class="name"><?=$name?></h3>
										<div class="sub">
											<!-- ДОБАВЛЕНО: Динамический вывод бейджей из свойства HIT -->
<?php if (!empty($arProps['HIT']['VALUE'])): ?>
    <?php foreach ($arProps['HIT']['VALUE'] as $badge): ?>
      <?  $badgeClass = '';
if ($badge == 'Хит') $badgeClass = 'badge-hit';
elseif ($badge == 'Акция') $badgeClass = 'badge-sale';
elseif ($badge == 'Новинка') $badgeClass = 'badge-new';
echo '<div class="tf-product-tag text-caption-1 ' . $badgeClass . '">' . $badge . '</div>'; ?>
    <?php endforeach; ?>
<?php endif; ?>
											<div class="tf-product-info-rate">
												<div class="list-star-default">
													<i class="icon icon-star"></i>
													<i class="icon icon-star"></i>
													<i class="icon icon-star"></i>
													<i class="icon icon-star"></i>
													<i class="icon icon-star"></i>
												</div>
												<div class="text text-caption-1">(<?=$arResult['REVIEWS_COUNT'] ?? 134?> отзыва)</div>
											</div>
											<div class="tf-product-info-sold">
												<svg width="20" height="20" viewbox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
													<path d="M16.7076 9.80077L7.95759 19.1758C7.86487 19.2747 7.74247 19.3408 7.60888 19.3641C7.47528 19.3874 7.33773 19.3666 7.21699 19.3049C7.09625 19.2432 6.99886 19.1438 6.93953 19.0219C6.88019 18.8999 6.86213 18.762 6.88806 18.6289L8.03338 12.9L3.53103 11.2094C3.43434 11.1732 3.34811 11.1136 3.28005 11.036C3.21199 10.9584 3.16422 10.8651 3.14101 10.7645C3.11779 10.6639 3.11986 10.5591 3.14702 10.4595C3.17418 10.3599 3.22559 10.2686 3.29666 10.1937L12.0467 0.818744C12.1394 0.719788 12.2618 0.653675 12.3954 0.630383C12.529 0.60709 12.6665 0.627882 12.7873 0.68962C12.908 0.751359 13.0054 0.850694 13.0647 0.972636C13.1241 1.09458 13.1421 1.23251 13.1162 1.36562L11.9677 7.10077L16.4701 8.78906C16.5661 8.82547 16.6516 8.88496 16.7191 8.96228C16.7867 9.0396 16.8341 9.13236 16.8573 9.23237C16.8805 9.33237 16.8786 9.43655 16.852 9.53569C16.8253 9.63482 16.7747 9.72587 16.7045 9.80077H16.7076Z" fill="#DC9056"></path>
												</svg>
												<?
$salesKey = 'product_sales_' . $arResult['ID'];
// Динамическое количество продаж (меняется не чаще 1 раза за 2 дня)
$currentTime = time();
$salesChangeInterval = 2 * 24 * 60 * 60; // 2 дня в секундах

if (!isset($_SESSION[$salesKey]) || ($currentTime - $_SESSION[$salesKey]['timestamp']) > $salesChangeInterval) {
    $_SESSION[$salesKey] = [
        'value' => rand(5, 45),
        'timestamp' => $currentTime
    ];
}

$salesCount = $_SESSION[$salesKey]['value'];

// ДОБАВЛЕНО: Функция для склонения слова "продажа"
function getSalesWord($count) {
    $count = abs($count) % 100; // Учитываем только последние две цифры
    if ($count > 10 && $count < 20) {
        return 'продаж'; // Для 11-19
    }
    $lastDigit = $count % 10;
    if ($lastDigit == 1) {
        return 'продажа'; // Для 1, 21, 31 и т.д.
    } elseif ($lastDigit >= 2 && $lastDigit <= 4) {
        return 'продажи'; // Для 2-4, 22-24 и т.д.
    } else {
        return 'продаж'; // Для 5-10, 20, 25-30 и т.д.
    }
}
?>
<div class="text text-caption-1"><?=$salesCount?> <?=getSalesWord($salesCount)?> за последние 30 дней</div>

										</div>
									</div>
									<div class="tf-product-info-desc">
										<div class="tf-product-info-price">
											<?php
											// Логика для вывода цен
											$currentPrice = $price['PRICE'] ?? 0;
											$basePrice = $price['BASE_PRICE'] ?? $currentPrice; // Если нет BASE_PRICE, используем текущую
											$currency = $price['CURRENCY'] ?? 'RUB';
											$discountPercent = 0;
											if ($basePrice > $currentPrice && $basePrice > 0) {
												$discountPercent = round((($basePrice - $currentPrice) / $basePrice) * 100);
											}
											$showDiscount = $discountPercent > 0;
											$savingsAmount = $basePrice - $currentPrice;
											$printSavings = number_format($savingsAmount, 0, '.', ' ') . ' ₽';
											?>
											<div class="product-item-detail-price-current" id="<?=$itemIds['PRICE_ID']?>">
												<?=number_format($currentPrice, 0, '.', ' ')?> ₽
											</div>
											<?php if ($arParams['SHOW_OLD_PRICE'] === 'Y'): ?>
												<div class="product-item-detail-price-old" id="<?=$itemIds['OLD_PRICE_ID']?>"
													style="display: <?=($showDiscount ? '' : 'none')?>;">
													<?=($showDiscount ? number_format($basePrice, 0, '.', ' ') . ' ₽' : '')?>
												</div>
											<?php endif; ?>
											<?php if ($arParams['SHOW_OLD_PRICE'] === 'Y'): ?>
												<div class="item_economy_price" id="<?=$itemIds['DISCOUNT_PRICE_ID']?>"
													style="display: <?=($showDiscount ? '' : 'none')?>;">
													<?php
													if ($showDiscount) {
														echo 'Вы экономите ' . $printSavings;
													}
													?>
												</div>
											<?php endif; ?>
											<?php if ($discountPercent > 0): ?>
												<div class="badges-on-sale text-btn-uppercase">-<?=$discountPercent?>%</div>
											<?php endif; ?>
										</div>										
                                        <p>
    <?php
    if (!empty($arResult['PREVIEW_TEXT'])) {
        // Если PREVIEW_TEXT заполнено, выводим только его
        echo $arResult['PREVIEW_TEXT'];
    } else {
        // Иначе формируем описание на основе свойств
        $description = 'Керамогранит соответствуют европейскому стандарту EN 14 411 EU, является качественным, прочным и экологически чистым продуктом.';
        
        $properties = [];
        if (!empty($arProps['ISTIR']['VALUE'])) {
            $properties[] = 'истираемостью  ' . $arProps['ISTIR']['VALUE'];
        }
        if (!empty($arProps['MOROZ']['VALUE'])) {
            $properties[] = 'морозоустойчивостью ' . $arProps['MOROZ']['VALUE'];
        }
        if (!empty($arProps['PROTIVO']['VALUE'])) {
            $properties[] = 'противоскольжением ' . $arProps['PROTIVO']['VALUE'];
        }
        if (!empty($arProps['UST']['VALUE'])) {
            $properties[] = 'устойчивостью к кислотам ';
        }
        
        if (!empty($properties)) {
            $description = 'Керамогранит обладает ' . implode(', ', $properties) . ', является качественным, прочным и экологически чистым продуктом.';
        }
        
        echo $description;
    }
    ?>
</p>


										<?
										// Динамическое количество просмотров (меняется не чаще 1 раза за 15 минут)
$viewsKey = 'product_views_' . $arResult['ID'];
$currentTime = time();
$viewsChangeInterval = 15 * 60; // 15 минут в секундах

if (!isset($_SESSION[$viewsKey]) || ($currentTime - $_SESSION[$viewsKey]['timestamp']) > $viewsChangeInterval) {
    $_SESSION[$viewsKey] = [
        'value' => rand(2, 15),
        'timestamp' => $currentTime
    ];
}

$viewsCount = $_SESSION[$viewsKey]['value'];
?>
										
<div class="tf-product-info-liveview">
    <i class="icon icon-eye"></i>
    <p class="text-caption-1">
        <span class="liveview-count"><?=$viewsCount?></span>
        человек в данный момент смотрят этот товар 
    </p>
</div>
									</div>
								</div>
<?/*?>

								<div class="productCard__props desktop">
									<?php
									// Mapping of property codes to icons, labels, and units
									$propertyMappings = [
										'TOLST' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/thickness.png',
											'label' => 'Толщина',
											'unit' => ' мм'
										],
										'MT' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/paper.png',
											'label' => 'Тип покрытия',
											'unit' => ''
										],
										'PRIM' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/floor.png',
											'label' => 'Применение',
											'unit' => ''
										],
										'ISTIR' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/grinding.png',
											'label' => 'Истираемость PEI',
											'unit' => ''
										],
										'MOROZ' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/temperatures.png',
											'label' => 'Морозоустойчивость F',
											'unit' => ''
										],
										'PROTIVO' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/waterproof.png',
											'label' => 'Противоскольжение R',
											'unit' => ''
										],
										'UST' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/durable.png',
											'label' => 'Устойчивость к кислотам',
											'unit' => ''
										],
										'FORMAT' => [
											'icon' => SITE_TEMPLATE_PATH . '/images/tiles/maximize.png',
											'label' => 'Формат (см)',
											'unit' => ''
										],
										'ARTICLE' => [
											'icon' => '',
											'label' => 'Артикул',
											'unit' => ''
										],
										'VES' => [
											'icon' => '',
											'label' => 'Вес',
											'unit' => ' кг'
										]/*,
										'COLLECTIONS' => [
											'icon' => '',
											'label' => 'Коллекция',
											'unit' => ''
										]*/
								/*	];

									// Display dynamic properties
									foreach ($propertyMappings as $code => $mapping) {
										if (!empty($arProps[$code]['VALUE'])) {
											$value = is_array($arProps[$code]['VALUE']) ? implode(', ', $arProps[$code]['VALUE']) : $arProps[$code]['VALUE'];
											$value .= $mapping['unit'];
											?>
											<div class="productCard__propsItem">
												<div class="productCard__props-icon-wrapper">
													<?php if (!empty($mapping['icon'])): ?>
														<img class="productCard__props-icon" width="28" height="28" src="<?=$mapping['icon']?>" alt="">
													<?php endif; ?>
													<p class="productCard__propsKey"><?=$mapping['label']?>: </p>
													<p class="productCard__propsValue"><?=$value?></p>
												</div>
											</div>
											<?php
										}
									}

									// Static properties (if needed, can be removed or made conditional)
									?>
									<div class="productCard__propsItem">
										<div class="productCard__props-icon-wrapper">
											<img class="productCard__props-icon" src="<?=SITE_TEMPLATE_PATH;?>/images/tiles/waterproof.png" height="28" width="28" alt="Низкое водопоглощение">
											<span class="productCard__propsKey">Низкое водопоглощение</span>
										</div>
									</div>
									<div class="productCard__propsItem">
										<div class="productCard__props-icon-wrapper">
											<img class="productCard__props-icon" src="<?=SITE_TEMPLATE_PATH;?>/images/tiles/temperatures.png" height="28" width="28" alt="Устойчивость к перепадам температур">
											<span class="productCard__propsKey">Устойчивость к перепадам t°</span>
										</div>
									</div> <? */ ?>
                                   <div class="productCard__props desktop">
    <?php
    // Маппинг для иконок и дефолтных свойств
    $iconMappings = [
        'Толщина' => SITE_TEMPLATE_PATH . '/images/tiles/thickness.png',
        'Формат (см)' => SITE_TEMPLATE_PATH . '/images/tiles/maximize.png',
        'Полированная' => SITE_TEMPLATE_PATH . '/images/tiles/grinding.png',
        'Ректификат' => SITE_TEMPLATE_PATH . '/images/tiles/maximize.png',
        'Подходит для стен и пола' => SITE_TEMPLATE_PATH . '/images/tiles/floor.png',
        'Повышенная прочность' => SITE_TEMPLATE_PATH . '/images/tiles/durable.png',
        'Низкое водопоглощение' => SITE_TEMPLATE_PATH . '/images/tiles/waterproof.png',
        'Устойчивость к перепадам t°' => SITE_TEMPLATE_PATH . '/images/tiles/temperatures.png',
    ];

    // Дефолтные свойства (если PRODUCT_SPECIFICATIONS пустое)
    $defaultSpecs = [
        'Полированная',
        'Подходит для стен и пола',
        'Низкое водопоглощение',
        'Ректификат',
        'Повышенная прочность',
        'Устойчивость к перепадам t°',
    ];

    // Вывод TOLST (Толщина)
    if (!empty($arProps['TOLST']['VALUE'])) {
        $value = $arProps['TOLST']['VALUE'] . ' мм';
        ?>
        <div class="productCard__propsItem">
            <div class="productCard__props-icon-wrapper">
                <img class="productCard__props-icon" width="26" height="25" src="<?=$iconMappings['Толщина']?>" alt="">
                <p class="productCard__propsKey">Толщина: </p>
                <p class="productCard__propsValue"><?=$value?></p>
            </div>
        </div>
        <?php
    }

  //  <!-- Вывод FORMAT (Формат) -->
 if (!empty($arProps['FORMAT']['VALUE'])): ?>
    <?php
    $value = is_array($arProps['FORMAT']['VALUE']) ? implode(', ', $arProps['FORMAT']['VALUE']) : $arProps['FORMAT']['VALUE'];
    ?>
    <div class="productCard__propsItem">
        <div class="productCard__props-icon-wrapper">
            <img class="productCard__props-icon" width="27" height="25" src="<?=$iconMappings['Формат (см)']?>" alt="">
            <p class="productCard__propsKey">Формат: </p>
            <p class="productCard__propsValue"><?=$value?></p>
        </div>
    </div>
<?php endif; 


    // Определяем, какие спецификации выводить
    $specsToShow = [];
    if (!empty($arProps['PRODUCT_SPECIFICATIONS']['VALUE'])) {
        // Если PRODUCT_SPECIFICATIONS заполнено, берём только выбранные
        $specsToShow = is_array($arProps['PRODUCT_SPECIFICATIONS']['VALUE']) ? $arProps['PRODUCT_SPECIFICATIONS']['VALUE'] : [$arProps['PRODUCT_SPECIFICATIONS']['VALUE']];
    } else {
        // Иначе — все дефолтные
        $specsToShow = $defaultSpecs;
    }

    // Вывод спецификаций
    foreach ($specsToShow as $spec) {
        if (isset($iconMappings[$spec])) {
            ?>
            <div class="productCard__propsItem">
                <div class="productCard__props-icon-wrapper">
                    <img class="productCard__props-icon" width="28" height="28" src="<?=$iconMappings[$spec]?>" alt="">
                    <span class="productCard__propsKey"><?=$spec?></span>
                </div>
            </div>
            <?php
        }
    }
    ?>
</div>

								<div class="tf-product-info-choose-option gap-19">
									<div class="tf-product-info-quantity">
										<div class="title mb_12">Количество:</div>
										<div class="wg-quantity">
											<span class="btn-quantity btn-decrease">-</span>
											<input class="quantity-product" type="text" name="number" value="<?=$price['MIN_QUANTITY']?>" id="<?=$itemIds['QUANTITY_ID']?>">
											<span class="btn-quantity btn-increase">+</span>
										</div>
									</div>
									<div>
											<div class="tf-product-info-by-btn mb_12">
											<?// Отладка перед кнопками
echo "<!-- DEBUG BEFORE BUTTONS: element['ID'] = " . $element['ID'] . " -->";?>
											<a class="tf-btn btn-onsurface flex-grow-1 show-shopping-cart btn-main-product main-product" id="<?=$itemIds['ADD_BASKET_LINK']?>" data-product-id="<?=$element['ID']?>">
    <span>В корзину -&nbsp;</span>
    <span class="tf-qty-price total-price"><?=$price['PRINT_RATIO_PRICE']?></span>
</a>

<a href="javascript:void(0);" class="box-icon hover-tooltip compare main-product" id="<?=$itemIds['COMPARE_LINK']?>" data-product-id="<?=$element['ID']?>">
    <span class="icon icon-compare"></span>
    <span class="tooltip text-caption-2">Сравнение</span>
</a>

<a href="javascript:void(0);" class="box-icon hover-tooltip text-caption-2 wishlist btn-icon-action main-product" data-product-id="<?=$element['ID']?>">
    <span class="icon icon-heart"></span>
    <span class="tooltip text-caption-2">Избранное</span>
</a>
										</div>
										<a href="#" class="tf-btn btn-primary w-full" id="<?=$itemIds['BUY_LINK']?>">Купить сейчас</a>
									</div>
									<div class="tf-product-info-help gap-12">
										<div class="wrap">
											<div class="dropdown dropdown-store-location">
												<div class="dropdown-title dropdown-backdrop" data-bs-toggle="dropdown" aria-haspopup="true">
													<div class="tf-product-info-view link">
														<span>Информация о наличии в торговой точке</span>
													</div>
												</div>
												<div class="dropdown-menu dropdown-menu-end">
													<div class="dropdown-content">
														<div class="dropdown-content-heading">
															<h5>Локация</h5>
															<i class="icon icon-close"></i>
														</div>
														<div class="line-bt"></div>
														<div>
															<h6><?=$name?></h6>
															<p>Возможна доставка. Товар будет готов к отгрузке в течение 24 часов.</p>
														</div>
														<div>
															<p>Краснодарский край, г. Краснодар</p>
															<p>ул. Бульвар им. Адмирала Пустошкина 11/2, помещение 29-30.</p>
														</div>
													</div>
												</div>
											</div>
										</div>
										<div class="tf-product-info-time">
											<div class="icon">
												<i class="icon-time"></i>
											</div>
											<p class="text-caption-1">Предполагаемая доставка:&nbsp;&nbsp;<span>24 часа</span> по Краснодару, <span>3-6 дней </span> Краснодарский край</p>
										</div>
										<div class="tf-product-info-return">
											<div class="icon">
												<i class="icon-arrowclockwise"></i>
											</div>
											<p class="text-caption-1">Возврат в течение <span>45 дней</span> с момента покупки.</p>
										</div>
										<div class="tf-product-info-extra-link">
											<a href="#delivery_return" data-bs-toggle="modal" class="tf-product-extra-icon">
												<div class="icon">
													<i class="icon-shipping"></i>
												</div>
												<p class="text-caption-1">Оплата &amp; Доставка</p>
											</a>
											<a href="#ask_question" data-bs-toggle="modal" class="tf-product-extra-icon">
												<div class="icon">
													<i class="icon-question"></i>
												</div>
												<p class="text-caption-1">Задать вопрос</p>
											</a>
											<a href="#share_social" data-bs-toggle="modal" class="tf-product-extra-icon">
												<div class="icon">
													<i class="icon-share"></i>
												</div>
												<p class="text-caption-1">Поделиться</p>
											</a>
										</div>
									</div>
									<ul class="tf-product-info-sku">
										<?php if (!empty($arResult['PROPERTIES']['ARTICLE']['VALUE'])): ?>
										<li>
											<p class="text-caption-1">Артикул:</p>
											<p class="text-caption-1 text-1"><?=$arResult['PROPERTIES']['ARTICLE']['VALUE']?></p>
										</li>
										<?php endif; ?>										
										<li>
											<p class="text-caption-1">Наличие:</p>
											<p class="text-caption-1 text-1"><?=$actualItem['CAN_BUY'] ? 'Много' : 'Нет в наличии'?></p>
										</li>
										<li>
											<p class="text-caption-1">Коллекция:</p>
											<p class="text-caption-1"><a href="<?=$collectionUrl?>" class="text-1 link"><?=$collectionName ?: 'Коллекция'?></a></p>
										</li>
									</ul>
                                    <?/*
									<div class="tf-product-info-guranteed">
										<div class="text-title">
											Принимаем оплату:
										</div>
										<div class="tf-payment d-flex">
											<a href="#">
												<img src="images/payment/mir.png" alt="">
											</a>
											<a href="#">
												<img src="images/payment/mir.png" alt="">
											</a>
											<a href="#">
												<img src="images/payment/mir.png" alt="">
											</a>
											<a href="#">
												<img src="images/payment/mir.png" alt="">
											</a>
											<a href="#">
												<img src="images/payment/mir.png" alt="">
											</a>
											<a href="#">
												<img src="images/payment/mir.png" alt="">
											</a>
										</div>
									</div>*/ ?>
								</div>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div></div>
		<div class="tf-sticky-btn-atc">
			<div class="container">
				<div class="row">
					<div class="col-12">
						<form class="form-sticky-atc">
							<div class="tf-sticky-atc-product">
								<div class="image">
									<img class="lazyload" data-src="<?=$actualItem['MORE_PHOTO'][0]['SRC'] ?? ''?>" alt="" src="<?=$actualItem['MORE_PHOTO'][0]['SRC'] ?? ''?>">
								</div>
								<div class="content">
									<div class="text-title">
										<?=$name?>
									</div>
									<div class="text-caption-1 text-secondary-2">Коллекция: <?=$collectionName ?: 'Не указана'?></div>
									<div class="text-title"><?=$price['PRINT_RATIO_PRICE']?></div>
								</div>
							</div>
							<div class="tf-sticky-atc-infos">
								<div class="tf-sticky-atc-quantity d-flex gap-12 align-items-center">
									<div class="tf-sticky-atc-infos-title text-title">Количество:</div>
									<div class="wg-quantity style-1">
										<span class="btn-quantity minus-btn">-</span>
										<input type="text" name="number" value="<?=$price['MIN_QUANTITY']?>">
										<span class="btn-quantity plus-btn">+</span>
									</div>
								</div>
								<div class="tf-sticky-atc-btns">
									<a href="javascript:void(0);" class="btn-main-product" data-product-id="<?=$element['ID']?>">В корзину</a>
								</div>
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
	</section>
	<!-- /Section product -->

	<section class="">
   <div class="container">
   <div class="row">
   <div class="col-12">
   <div class="widget-tabs style-1">
      <ul class="widget-menu-tab">
         <li class="item-title active">
            <span class="inner">Описание</span>
         </li>
         <li class="item-title">
            <span class="inner">Отзывы</span>
         </li>
         <li class="item-title">
            <span class="inner">Оплата и возврат</span>
         </li>
         <li class="item-title">
            <span class="inner">Политика возврата</span>
         </li>
      </ul>
      <div class="widget-content-tab">
         <div class="widget-content-inner active">
            <div class="tab-description">
               <div class="right">
                  <h6 class=" mb_12">О товаре</h6>
                  <p class="mb_8 text_secondary"><?=$arResult['DETAIL_TEXT'] ?: 'Описание отсутствует'?></p>  
               </div>
               <div class="left">
                  <h6 class="mb_12">Характеристики</h6>
                  <ul class="">
                     <?php
                        foreach ($propertyMappings as $code => $mapping) {
                           if (!empty($arProps[$code]['VALUE'])) {
                              $value = is_array($arProps[$code]['VALUE']) ? implode(', ', $arProps[$code]['VALUE']) : $arProps[$code]['VALUE'];
                              $value .= $mapping['unit'];
                              ?>
<li class="text-body-default mb_8"><?=$mapping['label']?>: <span class="text_secondary"><?=$value?></span></li>
                              <?php
                           }
                        }
                        ?>
<li class="text-body-default mb_8">Вес: <span class="text_secondary">2.5 кг</span></li>
               </div>
            </div>
         </div>
         <div class="widget-content-inner">
            <div class="tab-reviews  write-cancel-review-wrap">
               <div class="tab-reviews-heading">
                  <div class="top">
                     <div class="text-center">
                        <div class="number text-display">4.9</div>
                        <div class="list-star">
                           <i class="icon icon-star"></i>
                           <i class="icon icon-star"></i>
                           <i class="icon icon-star"></i>
                           <i class="icon icon-star"></i>
                           <i class="icon icon-star"></i>
                        </div>
                        <p>(168 отзывов)</p>
                     </div>
                     <div class="rating-score">
                        <div class="item">
                           <div class="number-1 text-caption-1">5</div>
                           <i class="icon icon-star"></i>
                           <div class="line-bg">
                              <div style="width: 94.67%;"></div>
                           </div>
                           <div class="number-2 text-caption-1">59</div>
                        </div>
                        <div class="item">
                           <div class="number-1 text-caption-1">4</div>
                           <i class="icon icon-star"></i>
                           <div class="line-bg">
                              <div style="width: 60%;"></div>
                           </div>
                           <div class="number-2 text-caption-1">46</div>
                        </div>
                        <div class="item">
                           <div class="number-1 text-caption-1">3</div>
                           <i class="icon icon-star"></i>
                           <div class="line-bg">
                              <div style="width: 0%;"></div>
                           </div>
                           <div class="number-2 text-caption-1">0</div>
                        </div>
                        <div class="item">
                           <div class="number-1 text-caption-1">2</div>
                           <i class="icon icon-star"></i>
                           <div class="line-bg">
                              <div style="width: 0%;"></div>
                           </div>
                           <div class="number-2 text-caption-1">0</div>
                        </div>
                        <div class="item">
                           <div class="number-1 text-caption-1">1</div>
                           <i class="icon icon-star"></i>
                           <div class="line-bg">
                              <div style="width: 0%;"></div>
                           </div>
                           <div class="number-2 text-caption-1">0</div>
                        </div>
                     </div>
                     <div>
                        <div class="tf-btn btn-white has-border radius-4 btn-comment-review btn-cancel-review">
                           Отменить
                        </div>
                        <div class="tf-btn btn-white has-border radius-4 btn-comment-review btn-write-review">
                           Написать отзыв
                        </div>
                     </div>
                  </div>
                  <div class="reply-comment style-1 cancel-review-wrap">
                     <div class="d-flex mb_24 gap-20 align-items-center justify-content-between flex-wrap">
                        <h4 class="">03 комментария</h4>
                        <div class="d-flex align-items-center gap-12">
                           <div class="text-caption-1">Сортировать:</div>
                           <div class="tf-dropdown-sort" data-bs-toggle="dropdown">
                              <div class="btn-select">
                                 <span class="text-sort-value">Самые популярные</span>
                                 <span class="icon icon-down"></span>
                              </div>
                              <div class="dropdown-menu">
                                 <div class="select-item active">
                                    <span class="text-value-item">Последние</span>
                                 </div>
                                 <div class="select-item">
                                    <span class="text-value-item">Старые</span>
                                 </div>
                                 <div class="select-item">
                                    <span class="text-value-item">Самые популярные</span>
                                 </div>
                              </div>
                           </div>
                        </div>
                     </div>
                     <div class="reply-comment-wrap">
                        <div class="reply-comment-item">
                           <div class="user">
                              <div class="image">
                                 <img src="images/avatar/user-default.jpg" alt="">
                              </div>
                              <div>
                                 <h6>
                                    <a href="#" class="link">Иванов И.И.</a>
                                 </h6>
                                 <div class="day text-secondary-2 text-caption-1">5 дней назад</div>
                              </div>
                           </div>
                           <p class="text-secondary">Отличный магазин. Большой ассортимент, на любой вкус, цвет и кошелёк. Найдётся именно нужная плитка.Работают в интересах клиента. Рекомендую!</p>
                        </div>
                        <div class="reply-comment-item type-reply">
                           <div class="user">
                              <div class="image">
                                 <img src="images/avatar/admin-default.jpg" alt="">
                              </div>
                              <div>
                                 <h6>
                                    <a href="#" class="link">Админ</a>
                                 </h6>
                                 <div class="day text-secondary-2 text-caption-1">5 дней назад</div>
                              </div>
                           </div>
                           <p class="text-secondary">Спасибо за положительный отзыв. Приходите к нам еще</p>
                        </div>
                        <div class="reply-comment-item">
                           <div class="user">
                              <div class="image">
                                 <img src="images/avatar/user-default.jpg" alt="">
                              </div>
                              <div>
                                 <h6>
                                    <a href="#" class="link">Ксения А.</a>
                                 </h6>
                                 <div class="day text-secondary-2 text-caption-1">3 дня назад</div>
                              </div>
                           </div>
                           <p class="text-secondary">Покупали плитку в этом магазине, менеджер Умид, очень приветливый, сделал просчет быстро, плитка пришла вовремя. Рекомендую 🫶🏻цена качество - супер</p>
                        </div>
                     </div>
                  </div>
                  <form class="form-write-review write-review-wrap">
                     <div class="heading">
                        <h4>Написать отзыв:</h4>
                        <div class="list-rating-check">
                           <input type="radio" id="star5" name="rate" value="5">
                           <label for="star5" title="text"></label>
                           <input type="radio" id="star4" name="rate" value="4">
                           <label for="star4" title="text"></label>
                           <input type="radio" id="star3" name="rate" value="3">
                           <label for="star3" title="text"></label>
                           <input type="radio" id="star2" name="rate" value="2">
                           <label for="star2" title="text"></label>
                           <input type="radio" id="star1" name="rate" value="1">
                           <label for="star1" title="text"></label>
                        </div>
                     </div>
                     <div class="mb_32">
                        <div class="mb_8">Заголовок отзыва</div>
                        <fieldset class="mb_20">
                           <input class="" type="text" placeholder="Напишите заголовок отзыва" name="text" tabindex="2" value="" aria-required="true" required="">
                        </fieldset>
                        <div class="mb_8">Текст отзыва</div>
                        <fieldset class="d-flex mb_20">
                           <textarea class="" rows="4" placeholder="Напишите отзыв" tabindex="2" aria-required="true" required=""></textarea>
                        </fieldset>
                        <div class="cols mb_20">
                           <fieldset class="">
                              <input class="" type="text" placeholder="Имя" name="text" tabindex="2" value="" aria-required="true" required="">
                           </fieldset>
                           <fieldset class="">
                              <input class="" type="email" placeholder="E-mail" name="email" tabindex="2" value="" aria-required="true" required="">
                           </fieldset>
                        </div>
                        <div class="d-flex align-items-center check-save gap-12">
                           <input type="checkbox" name="availability" class="tf-check" id="check1">
                           <label class="text-secondary text-caption-1" for="check1">Согласие на обработку персональных данных</label>
                        </div>
                     </div>
                     <div class="button-submit">
                        <button class="tf-btn btn-onsurface radius-4" type="submit">Разместить отзыв</button>
                     </div>
                  </form>
               </div>
             
            </div>
         </div>
		   <div class="widget-content-inner">
                  <div class="tab-shipping">
                     <div class="w-100">
                        <h6 class="text-btn-uppercase mb_12">Оплата</h6>
                        <p class="mb_12">Вы можете оплатить товар на нашем сайте. После оформления заказа в корзине, с вами свяжется менеджер, уточнит наличие товара и дату доставки и вышлет вам ссылку для оплаты.</p>
                        <p class="">Если товар отправляется вам через транспортную компанию, то 100% предоплата обязательна.</p>
                     </div>
                     <div class="w-100">
                        <h6 class="text-btn-uppercase mb_12">Условия возврата</h6>
                        <p>Для начала процедуры возврата приобретенного у нас товара обратитесь непосредственно в магазин с заявлением о возврате товара, товарным и/или кассовым чеком, а также предоставьте товар в полной комплектации.</p>
                        <p>Вы вправе обменять товар надлежащего качества на аналогичный товар в течение 14 дней, не считая дня покупки.</p>
                     </div>
                     <div class="w-100">
                        <h6 class="text-btn-uppercase mb_12">Быстрая доставка</h6>
                        <p class="mb_6 font-2">Экспресс доставка: 3-5 дней</p>
                        <p class="font-2">Доставка по терриотрии РФ</p>
                     </div>
                     <div class="w-100">
                        <h6 class="text-btn-uppercase mb_12">Остались еще вопросы?</h6>
                        <div>
                           <a href="/faqs.html" class="link text-secondary text-decoration-underline mb_6">Часто задаваемые вопросы</a>
                        </div>
                     </div>
                  </div>
               </div>
               <div class="widget-content-inner">
                  <div class="tab-policies">
                     <h6 class=" mb_12">В случае приобретения товара в магазине</h6>
                     <p class="mb_12 text_secondary">Указанные товары, согласно Постановлению Правительства РФ от 19.01.1998 N 55, возврату и обмену как товары надлежащего качества не подлежат.</p>
                     <h6 class=" mb_12">В случае приобретения товара дистанционно в интернет - магазине</h6>
                     <ul class="list-text type-disc mb_12 gap-6">
                        <li class="text_secondary">Помимо права на обмен товара надлежащего качества, предусмотренного Законом РФ от 07.02.1992 N 2300-1 О защите прав потребителей, вы вправе отказаться от товара, приобретенного дистанционно, в любое время до его передачи, а также в течение семи дней после передачи товара.</li>
                        <li class="text_secondary">Если информация о порядке и сроках возврата товара надлежащего качества не была предоставлена вам в письменной форме в момент доставки товара — указанный срок продлевается согласно Правилам продажи товаров дистанционным способом.</li>
                     </ul>
                     <h6 class=" mb_12">Условия проведения гарантийного обслуживания:</h6>
                     <ul class="list-text type-number">
                        <li class="text_secondary">Фактическое наличие неисправного товара в момент обращения в сервисный центр;</li>
                        <li class="text_secondary">Гарантийное обслуживание товаров, гарантию на которые дает производитель, осуществляется в специализированных сервисных центрах;</li>
                        <li class="text_secondary">Гарантийное обслуживание осуществляется в течение всего гарантийного срока, установленного на товар;</li>
                     </ul>
                     <p class="text_secondary">Существенное нарушение качества товара должно быть подтверждено заключением (актом и др.) сервисного центра. В иных случаях, при наличии гарантийного обязательства продавца или производителя, Вы имеете право на гарантийный ремонт товара.</p>
                  </div>
               </div>
      </div>
   </div>
</section>

<?php
// ... existing code ...

// Fetch collection products
$collectionProducts = array();
if (!empty($arProps['COLLECTIONS']['VALUE'])) {
    $collectionIds = is_array($arProps['COLLECTIONS']['VALUE']) ? $arProps['COLLECTIONS']['VALUE'] : array($arProps['COLLECTIONS']['VALUE']);
    $arFilter = array(
        "IBLOCK_ID" => 12,
        "!ID" => $element['ID'],
        "PROPERTY_COLLECTIONS" => $collectionIds,
        "ACTIVE" => "Y"
    );
    $arSelect = array("ID", "NAME", "DETAIL_PAGE_URL", "PREVIEW_PICTURE");
    $res = CIBlockElement::GetList(array("SORT" => "ASC"), $arFilter, false, array("nTopCount" => 6), $arSelect);
    while ($arItem = $res->GetNext()) {
        // ИСПРАВЛЕНО: Обработка картинки (преобразование ID в путь)
        $arItem['PREVIEW_PICTURE_SRC'] = CFile::GetPath($arItem['PREVIEW_PICTURE']);
        
        // Get price
        $optimalPrice = CCatalogProduct::GetOptimalPrice($arItem['ID'], 1, $USER->GetUserGroupArray(), 'N', array(), SITE_ID);
        $arItem['PRICE'] = $optimalPrice['RESULT_PRICE']['PRICE'] ?? 0;
        $arItem['CURRENCY'] = $optimalPrice['RESULT_PRICE']['CURRENCY'] ?? 'RUB';
        
        // ДОБАВЛЕНО: Отладка для цены (уберите после проверки)
        echo "<!-- DEBUG PRICE FOR COLLECTION ID {$arItem['ID']}: {$arItem['PRICE']} -->";
        
        $collectionProducts[] = $arItem;
    }
}

// Fetch similar products
$similarProducts = array();
if ($section && !empty($section['ID'])) {
    $arFilter = array(
        "IBLOCK_ID" => $arResult['IBLOCK_ID'],
        "SECTION_ID" => $section['ID'],
        "!ID" => $element['ID'],
        "ACTIVE" => "Y"
    );
    $arSelect = array("ID", "NAME", "DETAIL_PAGE_URL", "PREVIEW_PICTURE");
    $res = CIBlockElement::GetList(array("SORT" => "ASC"), $arFilter, false, array("nTopCount" => 6), $arSelect);
    while ($arItem = $res->GetNext()) {
        // ИСПРАВЛЕНО: Обработка картинки (преобразование ID в путь)
        $arItem['PREVIEW_PICTURE_SRC'] = CFile::GetPath($arItem['PREVIEW_PICTURE']);
        
        // Get price
        $optimalPrice = CCatalogProduct::GetOptimalPrice($arItem['ID'], 1, $USER->GetUserGroupArray(), 'N', array(), SITE_ID);
        $arItem['PRICE'] = $optimalPrice['RESULT_PRICE']['PRICE'] ?? 0;
        $arItem['CURRENCY'] = $optimalPrice['RESULT_PRICE']['CURRENCY'] ?? 'RUB';
        
        // ДОБАВЛЕНО: Отладка для цены (уберите после проверки)
        echo "<!-- DEBUG PRICE FOR SIMILAR ID {$arItem['ID']}: {$arItem['PRICE']} -->";
        
        $similarProducts[] = $arItem;
    }
}

// ... existing code ...
?>
<section class="flat-spacing-7">
    <div class="container flat-animate-tab">
        <ul class="tab-product justify-content-center wow fadeInUp" data-wow-delay="0s" role="tablist">
            <li class="nav-tab-item" role="presentation">
                <a href="#relatedProducts" class="active h4" data-bs-toggle="tab">Товары из коллекции</a>
            </li>
            <li class="nav-tab-item" role="presentation">
                <a href="#recentlyViewed" class="h4" data-bs-toggle="tab">Похожие товары</a>
            </li>
        </ul>
        <div class="tab-content">
            <div class="tab-pane active show" id="relatedProducts" role="tabpanel">
                <div dir="ltr" class="swiper tf-sw-latest" data-preview="4" data-tablet="3" data-mobile="2" data-space-lg="30" data-space-md="30" data-space="15" data-pagination="1" data-pagination-md="1" data-pagination-lg="1">
                    <div class="swiper-wrapper">
                        <!-- Для товаров из коллекции -->
<?php if (!empty($collectionProducts)): ?>
    <?php foreach ($collectionProducts as $product): ?>
        <div class="swiper-slide">
            <div class="card-product style-1">
                <div class="card-product-wrapper">
                    <a href="<?=$product['DETAIL_PAGE_URL']?>" class="image-wrap">
                        <!-- ИСПРАВЛЕНО: Используем обработанный путь к картинке -->
                        <img class="lazyload img-product" data-src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" alt="<?=$product['NAME']?>">
                        <img class="lazyload img-hover" data-src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" alt="<?=$product['NAME']?>">
                    </a>
					<div class="list-product-btn">
                        <a href="javascript:void(0);" class="box-icon wishlist btn-icon-action" data-product-id="<?=$product['ID']?>">
                            <span class="icon icon-heart"></span>
                            <span class="tooltip">Избранное</span>
                        </a>
                        <a href="#compare" data-bs-toggle="modal" aria-controls="compare" class="box-icon compare" data-product-id="<?=$product['ID']?>">
                            <span class="icon icon-compare"></span>
                            <span class="tooltip">Сравнение</span>
                        </a>
                    </div>
                    <div class="list-btn-main">
                        <a href="javascript:void(0);" class="btn-main-product" data-product-id="<?=$product['ID']?>">В корзину</a>
                    </div>
                </div>
                <div class="card-product-info">
                    <a href="<?=$product['DETAIL_PAGE_URL']?>" class="title link"><?=$product['NAME']?></a>
                    <!-- ИСПРАВЛЕНО: Вывод цены с проверкой -->
                    <div class="price text-body-default"><?php echo $product['PRICE'] > 0 ? number_format($product['PRICE'], 0, '.', ' ') . ' ₽' : 'Цена не указана'; ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
                    </div>
                    <div class="sw-pagination-recent sw-dots type-circle justify-content-center"></div>
                </div>
            </div>
            <div class="tab-pane" id="recentlyViewed" role="tabpanel">
                <div dir="ltr" class="swiper tf-sw-latest" data-preview="4" data-tablet="3" data-mobile="2" data-space-lg="30" data-space-md="30" data-space="15" data-pagination="1" data-pagination-md="1" data-pagination-lg="1">
                    <div class="swiper-wrapper">
                       <!-- Для похожих товаров -->
<?php if (!empty($similarProducts)): ?>
    <?php foreach ($similarProducts as $product): ?>
        <div class="swiper-slide">
            <div class="card-product style-1">
                <div class="card-product-wrapper">
                    <a href="<?=$product['DETAIL_PAGE_URL']?>" class="image-wrap">
                        <!-- ИСПРАВЛЕНО: Используем обработанный путь к картинке -->
                        <img class="lazyload img-product" data-src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" alt="<?=$product['NAME']?>">
                        <img class="lazyload img-hover" data-src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" src="<?=$product['PREVIEW_PICTURE_SRC'] ?: ''?>" alt="<?=$product['NAME']?>">
                    </a>
                    <div class="list-product-btn">
                        <a href="javascript:void(0);" class="box-icon wishlist btn-icon-action" data-product-id="<?=$product['ID']?>">
                            <span class="icon icon-heart"></span>
                            <span class="tooltip">Избранное</span>
                        </a>
                        <a href="#compare" data-bs-toggle="modal" aria-controls="compare" class="box-icon compare" data-product-id="<?=$product['ID']?>">
                            <span class="icon icon-compare"></span>
                            <span class="tooltip">Сравнение</span>
                        </a>
                    </div>
                    <div class="list-btn-main">
                        <a href="javascript:void(0);" class="btn-main-product" data-product-id="<?=$product['ID']?>">В корзину</a>
                    </div>
                </div>
                <div class="card-product-info">
                    <a href="<?=$product['DETAIL_PAGE_URL']?>" class="title link"><?=$product['NAME']?></a>
                    <!-- ИСПРАВЛЕНО: Вывод цены с проверкой -->
                    <div class="price text-body-default"><?php echo $product['PRICE'] > 0 ? number_format($product['PRICE'], 0, '.', ' ') . ' ₽' : 'Цена не указана'; ?></div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
                    </div>
                    <div class="sw-pagination-recent sw-dots type-circle justify-content-center"></div>
                </div>
            </div>
        </div>
    </div>
</section>
<!-- /Related Products -->




		  <script type="text/javascript" src="<?=SITE_TEMPLATE_PATH;?>/js/nouislider.min.js"></script>
    <script type="text/javascript" src="<?=SITE_TEMPLATE_PATH;?>/js/count-down.js"></script>


        <script type="module" src="<?=SITE_TEMPLATE_PATH;?>/js/model-viewer.min.js"></script>
    <script type="module" src="<?=SITE_TEMPLATE_PATH;?>/js/zoom.js"></script>
    <script type="module" src="<?=SITE_TEMPLATE_PATH;?>/js/drift.min.js"></script>
<style>
    
        .productCard__props {

            display:flex;
            flex-wrap: wrap;
        }
        .productCard__propsItem {
            flex-basis: 50%; 
        }
        .productCard__props-icon-wrapper {
            display: flex;
        }
        .productCard__props-icon-wrapper img   {
    margin-left: 5px;
    margin-right: 5px;
}



@media (max-width: 1199px) {
    .productCard__props.desktop { display:block;}
}


/*region Sidebar */

.product-item-detail-pay-block {
	position: relative;
	margin-bottom: 15px;
	padding: 15px 15px 0;
	border: 1px solid #e4e4e4;
	border-radius: 3px;
	background-color: #fff;
}

.bx-retina .product-item-detail-pay-block { border-width: .5px; }

/*endregion*/

/*region Compare*/
.product-item-detail-compare-container {
	position: relative;
	margin: 0 -15px 1px -14px;
	padding: 4px 15px;
	min-height: 30px;
	border-top: 1px solid #e4e4e4;
	background: #f3f3f3;
	box-shadow: inset 0 1px 1px 0 #eee;
	color: #4e4e4e;
	vertical-align: middle;
	font-size: 12px;
	line-height: 20px;
	border-radius: 1px;
}

.product-item-detail-compare .checkbox {
	margin: 0;
	padding: 0;
}

/*endregion*/

/*region Price */
.product-item-detail-price-current {
	color: #d0021b;
	white-space: nowrap;
	font-weight: var(--ui-font-weight-bold);
	text-align: center;
	font-size: 30px;
	line-height: 33px;
	transition: font-size 200ms ease;
}

.product-item-detail-price-old {
	color: #bdbdbd;
	text-align: center;
	text-decoration: line-through;
	white-space: nowrap;
	font-weight: var(--ui-font-weight-bold);
	font-size: 14px;
	line-height: 17px;
}

.product-item-detail-economy-price {
	color: #333;
	text-align: center;
	/*white-space: nowrap;*/
	font-weight: var(--ui-font-weight-bold);
	font-size: 14px;
	line-height: 17px;
}

/*endregion*/

.product-item-detail-info-container-title {
	color: #a5a5a5;
	font-size: 12px;
}

/*region SCU */
.product-item-scu-container-title {
	color: #a5a5a5;
	font-size: 12px;
}

.product-item-scu-block { }

.product-item-scu-list { margin: 0; }

.product-item-scu-item-list {
	overflow: hidden;
	margin: 0;
	padding: 0;
	list-style: none;
}

.product-item-scu-item-color-container,
.product-item-scu-item-text-container {
	display: inline-block;
	box-sizing: border-box;
	padding: 5px 3px;
	max-width: 100%;
	vertical-align: middle;
}

.product-item-scu-item-text-container { min-width: 48px; }

.product-item-scu-item-color-container {
	max-width: 60px;
	width: 25%;
}

.product-item-scu-item-color-block {
	position: relative;
	padding-top: 100%;
	outline: 1px solid #c0cfda;
	cursor: pointer;
}

.bx-no-touch .product-item-scu-item-color-block { transition: outline 200ms ease; }

.bx-retina .product-item-scu-item-color-block { outline-width: .5px; }

.product-item-scu-item-color-container.selected .product-item-scu-item-color-block { outline-width: 2px; }

.bx-retina .product-item-scu-item.selected .product-item-scu-item-color-block { outline-width: 1px; }

.product-item-scu-item-color {
	position: absolute;
	top: 2px;
	right: 2px;
	bottom: 2px;
	left: 2px;
	background-position: center;
	background-size: contain;
	background-repeat: no-repeat;
}

.product-item-scu-item-text-block {
	position: relative;
	padding: 0 5px;
	outline: 1px solid #c0cfda;
	cursor: pointer;
}

.bx-no-touch .product-item-scu-item-text-block { transition: outline 300ms ease; }

.bx-retina .product-item-scu-item-text-block { outline-width: .5px; }

.product-item-scu-item-text-block:hover,
.product-item-scu-item-color-block:hover,
.product-item-scu-item-text-container.selected .product-item-scu-item-text-block,
.product-item-scu-item-color-container.selected .product-item-scu-item-color-block { outline-color: var(--theme-color-primary); }

.product-item-scu-item-text-container.selected .product-item-scu-item-text-block { outline-width: 2px; }

.bx-retina .product-item-scu-item.selected .product-item-scu-item-text-block { outline-width: 1px; }

.product-item-scu-item-text {
	overflow: hidden;
	max-width: 100%;
	vertical-align: middle;
	text-align: center;
	text-transform: uppercase;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 11px;
	line-height: 18px;
}

.product-item-scu-item-color-container.notallowed .product-item-scu-item-color-block,
.product-item-scu-item-text-container.notallowed .product-item-scu-item-text-block {
	position: relative;
	overflow: hidden;
	outline-color: #a9adb4 !important;
}

.product-item-scu-item-color-container.notallowed .product-item-scu-item-color-block:after,
.product-item-scu-item-text-container.notallowed .product-item-scu-item-text-block:after {
	position: absolute;
	top: -2px;
	right: -2px;
	bottom: -2px;
	left: -2px;
	background: rgba(255, 255, 255, 0.75) url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIyNiIgaGVpZ2h0PSIyNiIgdmlld0JveD0iMCAwIDI2IDI2Ij4gIDxwYXRoIGZpbGw9IiNBOUFEQjQiIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTE0LjM5MjEyNjgsMTMuMjMyMzAyOSBMMjUuOTkyLDEuNDE4IEwyNC42LDAgTDEyLjk5OTk5NTYsMTEuODE0NDM2NCBMMS40LDAgTDAuMDA5LDEuNDE5IEwxMS42MDc4Njg3LDEzLjIzMjI5NzUgTDAuNDczLDI0LjU3MyBMMS44NjUsMjUuOTkxIEwxMi45OTk5OTkzLDE0LjY1MDE2NDUgTDI0LjEzNCwyNS45OSBMMjUuNTI2LDI0LjU3MiBMMTQuMzkyMTI2OCwxMy4yMzIzMDI5IFoiLz48L3N2Zz4=) no-repeat center;
	background-size: 80% 80%;
	content: "";
}

.product-item-selected-scu-container { word-spacing: 1px }

.product-item-selected-scu {
	display: inline-block;
	overflow: hidden;
	padding: 0 5px;
	min-width: 22px;
	height: 22px;
	outline: 1px solid #c0cfda;
	outline-color: var(--theme-color-primary);
	vertical-align: middle;
	text-align: center;
	text-transform: uppercase;
	text-overflow: ellipsis;
	white-space: nowrap;
	font-size: 11px;
	line-height: 22px;
}

.bx-retina .product-item-selected-scu { outline-width: .5px; }

.product-item-selected-scu-color {
	background-position: center;
	background-size: 20px 20px;
	background-repeat: no-repeat;
}

/*endregion*/

/*region Amount*/
.product-item-amount {
	padding: 0 0 2px;
	text-align: center;
}

.product-item-amount-field-container {
	display: -webkit-box;
	display: -ms-flexbox;
	display: flex;
	-webkit-box-align: center;
	-ms-flex-align: center;
	align-items: center;
	position: relative;
	max-width: 150px;
	margin: 0 auto 15px;
}


.product-item-amount-field-btn-plus,
.product-item-amount-field-btn-minus {
	position: relative;
	display: block;
	min-width: 22px;
	height: 22px;
	border-radius: 2px;
	background-color: #f2f2f2;
	color: #fff;
	vertical-align: middle;
	text-align: center;
	cursor: pointer;
	transition: background 300ms ease, opacity 300ms ease;
}

.bx-retina .product-item-amount-field-btn-plus,
.bx-retina .product-item-amount-field-btn-minus { border-width: .5px; }

.product-item-amount-field-btn-plus:hover,
.product-item-amount-field-btn-minus:hover { background: #d3d3d3; }

.product-item-amount-field-btn-plus:after,
.product-item-amount-field-btn-minus:after {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
	content: "";
	opacity: .8;
	transition: opacity 300ms ease, background 300ms ease;
}

.product-item-amount-field-btn-disabled.product-item-amount-field-btn-plus:after,
.product-item-amount-field-btn-disabled.product-item-amount-field-btn-minus:after {
	opacity: .3 !important;
	cursor: default;
}

.product-item-amount-field-btn-disabled.product-item-amount-field-btn-plus:hover,
.product-item-amount-field-btn-disabled.product-item-amount-field-btn-minus:hover { background-color: #f2f2f2; }

.product-item-amount-field-btn-minus:after {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
	content: "";
	opacity: .8;
	transition: opacity 300ms ease;
}

.product-item-amount-field-btn-plus:after {
	background: url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzMCIgaGVpZ2h0PSIzMCIgdmlld0JveD0iMCAwIDMwIDMwIj4gIDxwYXRoIGZpbGw9IiM1MjVDNjgiIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTE4LDEyIEwxOCwwIEwxMiwwIEwxMiwxMiBMMCwxMiBMMCwxOCBMMTIsMTggTDEyLDMwIEwxOCwzMCBMMTgsMTggTDMwLDE4IEwzMCwxMiBMMTgsMTIgWiIvPjwvc3ZnPg==) no-repeat center;
	background-size: 10px;
}

.product-item-amount-field-btn-minus:after {
	background: url(data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHdpZHRoPSIzMCIgaGVpZ2h0PSIzMCIgdmlld0JveD0iMCAwIDMwIDMwIj4gIDxyZWN0IHdpZHRoPSIzMCIgaGVpZ2h0PSI2IiB5PSIxMiIgZmlsbD0iIzUyNUM2OCIgZmlsbC1ydWxlPSJldmVub2RkIi8+PC9zdmc+) no-repeat center;
	background-size: 10px;
}

.product-item-amount-field-btn-plus:hover:after,
.product-item-amount-field-btn-minus:hover:after { opacity: 1; }

.product-item-amount-field-block {
	-webkit-box-flex: 1;
	-ms-flex: 1;
	flex: 1;
}

.product-item-amount-field {
	display: block;
	padding: 0;
	width: 100%;
	height: 22px;
	border: 1px solid transparent;
	border-radius: 2px;
	background: transparent !important;
	color: #000;
	vertical-align: middle;
	text-align: center;
	font-weight: var(--ui-font-weight-bold);
	font-size: 19px;
	line-height: 22px;
	transition: border 300ms ease;
}

.bx-retina .product-item-amount-field { border-width: .5px; }

.product-item-amount-field:focus {
	outline: none !important;
	border-color: #006cc0;
	border-color: var(--theme-color-primary);
}

.bx-no-touch input.product-item-amount-field::-webkit-outer-spin-button,
.bx-no-touch input.product-item-amount-field::-webkit-inner-spin-button {
	margin: 0;
	-webkit-appearance: none;
	-moz-appearance: none;
	-ms-appearance: none;
	-o-appearance: none;
	appearance: none;
}

.product-item-amount-description-container {
	position: absolute;
	top: 100%;
	left: 0;
	right: 0;
	color: #a5a5a5;
	white-space: nowrap;
	font-size: 12px;
}

.product-item-amount-description-container strong {
	color: #333;
	white-space: nowrap;
}

.product-item-amount-label-container {
	margin: 5px 0;
	color: #a5a5a5;
	font-weight: var(--ui-font-weight-bold);
	font-size: 12px;
}

/**/
.product-item-quantity {
	color: #333;
	font-weight: normal;
	font-size: 12px;
}

/*endregion*/

/*region Buy button*/
.btn.product-item-detail-buy-button {
	position: relative;
	display: block;
	margin: 0 auto;
	max-width: 250px;
	width: 100%;
	padding-top: 12px;
	padding-bottom: 12px;
	font-weight: var(--ui-font-weight-bold);
	text-transform: uppercase;
}

.product-item-detail-short-card-btn .btn.product-item-detail-buy-button { margin: 0; }

.btn.product-item-detail-buy-button span {
	position: absolute;
	top: 50%;
	right: 0;
	left: 0;
	display: inline-block;
	min-height: 16px;
	max-width: 100%;
	white-space: normal;
	line-height: 16px;
	transform: translateY(-50%);
}

.btn-link.product-item-detail-buy-button { height: 25px; }

/*endregion*/

/*region Slider */
.product-item-detail-slider-container {
	position: relative;
	max-width: 100%;
	background: transparent;
	transition: background 800ms ease;
	z-index: 90;
}

.product-item-detail-slider-block {
	position: relative;
	box-sizing: border-box;
	padding-top: 56.25%;
	height: 0;
}

.product-item-detail-slider-block.product-item-detail-slider-block-square { padding-top: 100%; }

.product-item-detail-slider-images-container {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
}

.product-item-detail-slider-image {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
	text-align: center;
	opacity: 0;
	transition: opacity 250ms linear;
}

.product-item-detail-slider-image.active {
	z-index: 10;
	opacity: 1;
}

.product-item-detail-slider-image img {
	position: absolute;
	top: 50%;
	left: 50%;
	max-width: 100%;
	max-height: 100%;
	width: auto;
	height: auto;
	outline: 1px solid transparent;
	background-position: center;
	background-repeat: no-repeat;
	transition: background-size 450ms ease, width 450ms ease, height 450ms ease, outline 450ms ease;
	transform: translateY(-50%) translateX(-50%);
}

.product-item-detail-slider-progress-bar {
	position: absolute;
	bottom: 0;
	left: 0;
	z-index: 180;
	height: 3px;
	background-color: #006cc0;
	background-color: var(--theme-color-primary)
}

/*endregion*/

/*region Slider Controls*/
.product-item-detail-slider-controls-block {
	padding: 10px 0;
	text-align: center;
}

.product-item-detail-slider-controls-image {
	position: relative;
	display: inline-block;
	overflow: hidden;
	width: 65px;
	height: 65px;
	outline: 1px solid #c0cfda;
	background-color: #fff;
	text-align: center;
	cursor: pointer;
}

.product-item-detail-slider-controls-image:hover,
.product-item-detail-slider-controls-image.active { outline-color: var(--theme-color-primary); }

.bx-no-retina .product-item-detail-slider-controls-image.active { outline-width: 2px; }

.bx-retina .product-item-detail-slider-controls-image { outline-width: .5px; }

.bx-retina .product-item-detail-slider-controls-image.active { outline-width: 1px; }

.product-item-detail-slider-controls-image img {
	position: absolute;
	top: 50%;
	left: 50%;
	max-width: 65px;
	max-height: 65px;
	width: auto;
	height: auto;
	transform: translateY(-50%) translateX(-50%);
}

.product-item-detail-slider-left,
.product-item-detail-slider-right {
	position: absolute;
	top: 0;
	bottom: 0;
	z-index: 111;
	display: block;
	width: 25%;
	background: rgba(255, 255, 255, 0) url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2216%22%20height%3D%2230%22%20viewBox%3D%220%200%2016%2030%22%3E%3Cpolygon%20fill%3D%22%23000000%22%20fill-rule%3D%22evenodd%22%20points%3D%2216%2050%202%2036%2016%2022%2015%2021%201%2035%200%2036%201%2037%2015%2051%22%20transform%3D%22translate(0%20-21)%22%2F%3E%3C%2Fsvg%3E') no-repeat center;
	opacity: .57;
	cursor: pointer;
	transition: all 300ms ease;
}

.product-item-detail-slider-left { left: 0; }

.product-item-detail-slider-right {
	right: 0;
	transform: rotate(180deg);
}

.product-item-detail-slider-left:hover,
.product-item-detail-slider-right:hover {
	background-color: rgba(255, 255, 255, .4);
	opacity: .8;
}

.product-item-detail-slider-close { display: none; }

.product-item-detail-slider-container.popup .product-item-detail-slider-close {
	position: absolute;
	top: 4px;
	right: 110px;
	bottom: 0;
	z-index: 150;
	display: block;
	width: 50px;
	height: 50px;
	background: url('data:image/svg+xml;charset=US-ASCII,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%2216%22%20height%3D%2216%22%20viewBox%3D%220%200%2016%2016%22%3E%3Cpolygon%20fill%3D%22%23000000%22%20fill-rule%3D%22evenodd%22%20points%3D%2216%2015.1%208.944%208%2016%201%2015.006%200%208.05%207%20.994%200%200%201%207.056%208%200%2015.1%20.994%2016%208.05%209%2015.006%2016%22%2F%3E%3C%2Fsvg%3E') no-repeat center;
	opacity: .27;
	cursor: pointer;
	transition: all 300ms ease;
}

.product-item-detail-slider-container.popup .product-item-detail-slider-close:hover { opacity: 1; }

.product-item-detail-slider-container.popup .product-item-detail-slider-right { right: 105px; }

/*endregion*/

/*region magnified*/
.product-item-detail-slider-container.magnified .product-item-detail-slider-image { z-index: 100; }

.product-item-detail-slider-container.magnified .product-item-detail-slider-image img { outline-color: #ddd; }

.product-item-detail-slider-container.magnified .product-item-detail-slider-left,
.product-item-detail-slider-container.magnified .product-item-detail-slider-right { z-index: 50; }

.product-item-detail-slider-container.magnified .product-item-label-text,
.product-item-detail-slider-container.magnified .product-item-label-ring { opacity: 0; }

/*	>>>	popup*/
.product-item-detail-slider-container.popup {
	position: fixed;
	top: 0;
	right: 0;
	bottom: 0;
	left: 0;
	z-index: 999999;
	background: #fff;
	animation-name: imgPopupShow;
	animation-duration: 300ms;
}

.product-item-detail-slider-container.popup .product-item-detail-slider-images-container { right: 105px; }

.product-item-detail-slider-container.popup .product-item-detail-slider-block {
	padding-top: 0;
	height: 100vh;
}

.product-item-detail-slider-container.popup .product-item-detail-slider-image {
	position: absolute;
	top: 50%;
	left: 50%;
	max-height: 100vh;
	width: calc(100% - 150px);
	transform: translateY(-50%) translateX(-50%);
}

@-webkit-keyframes imgPopupShow {
	from { opacity: 0; }
	to { opacity: 1; }
}

@-moz-keyframes imgPopupShow {
	from { opacity: 0; }
	to { opacity: 1; }
}

@-ms-keyframes imgPopupShow {
	from { opacity: 0; }
	to { opacity: 1; }
}

@-o-keyframes imgPopupShow {
	from { opacity: 0; }
	to { opacity: 1; }
}

@keyframes imgPopupShow {
	from { opacity: 0; }
	to { opacity: 1; }
}

.product-item-detail-slider-container.popup .product-item-detail-slider-image img {
	max-width: 100%;
	max-height: 90vh;
	width: auto;
	cursor: default
}

.product-item-detail-slider-container.popup .product-item-detail-slider-controls-block {
	position: absolute;
	top: 0;
	right: 0;
	bottom: 0;
	overflow-y: auto;
	padding: 20px;
	border-left: 1px solid #d3d3d3;
	background-color: #fff;
}

.product-item-detail-slider-container.popup .product-item-detail-slider-controls-image {
	display: block;
	margin-bottom: 20px;
}

/*endregion*/

/*region  Slider Mobile*/
@media (max-width: 767px) {
	.product-item-detail-slider-container.popup .product-item-detail-slider-images-container { right: 0; }

	.product-item-detail-slider-container.popup .product-item-detail-slider-block {
		padding-top: 0;
		height: calc(100vh - 111px);
	}

	.product-item-detail-slider-container.popup .product-item-detail-slider-image { width: 90%; }

	.product-item-detail-slider-container.popup .product-item-detail-slider-image img {
		max-width: 100%;
		max-height: calc(100vh - 115px);
		width: auto;
		cursor: default;
	}

	.product-item-detail-slider-container.popup .product-item-detail-slider-controls-block {
		position: absolute;
		top: auto;
		right: 0;
		bottom: 0;
		left: 0;
		overflow-x: auto;
		max-height: 111px;
		border-top: 1px solid #d3d3d3;
		border-left: none;
		white-space: nowrap;
	}

	.product-item-detail-slider-container.popup .product-item-detail-slider-controls-image {
		display: inline-block;
		margin-bottom: 0;
	}

	.product-item-detail-slider-container.popup .product-item-detail-slider-close {
		right: 10px;
		z-index: 200;
	}

	.product-item-detail-slider-left,
	.product-item-detail-slider-right { max-width: 75px; }

	.product-item-detail-slider-container.popup .product-item-detail-slider-right { right: 0; }

}

/*endregion*/

/*region Label*/
.product-item-label-text,
.product-item-label-ring {
	position: absolute;
	z-index: 190;
	opacity: 1;
	transition: opacity 450ms ease;
}

.product-item-label-text span,
.product-item-label-ring {
	display: inline-block;
	clear: both;
	margin-bottom: 2px;
	background: #f42c2c;
	color: #fff;
	vertical-align: middle;
	text-align: center;
	transition: all 250ms ease;
}

.bx-red .product-item-label-text span,
.bx-red .product-item-label-ring { background-color: #fba90c; }

.product-item-label-text > div,
.product-item-label-ring > div { line-height: 0; }

.product-item-detail-slider-container.popup .product-item-label-text,
.product-item-detail-slider-container.popup .product-item-label-ring { display: none; }

/* TEXT */
.product-item-label-text span {
	padding: 2px 5px;
	vertical-align: middle;
	font-weight: var(--ui-font-weight-bold);
}

.product-item-label-text.product-item-label-small span {
	margin-bottom: 1px;
	padding: 2px 7px;
	font-weight: normal;
	font-size: 13px;
	line-height: 14px;
}

.product-item-label-text.product-item-label-big span {
	padding: 4px 10px;
	font-size: 13px;
	line-height: 17px;
}

@media (max-width: 767px) {
	.product-item-label-text > div { line-height: 0; }

	.product-item-label-text.product-item-label-small span,
	.product-item-scalable-card.hover .product-item-label-text.product-item-label-small span {
		margin-bottom: 1px;
		padding: 2px 7px;
		font-size: 10px;
		line-height: 14px;
	}

	.product-item-big-card .product-item-label-text.product-item-label-small span,
	.product-item-label-text.product-item-label-big span,
	.product-item-scalable-card.hover .product-item-label-text.product-item-label-big span {
		margin-bottom: 1px;
		padding: 3px 12px;
		font-size: 14px;
		line-height: 16px;
	}

	.product-item-big-card .product-item-label-text.product-item-label-big span {
		padding: 3px 12px;
		font-size: 20px;
		line-height: 25px;
	}
}

/*RING*/
.product-item-label-ring {
	box-sizing: border-box;
	border-radius: 50%;
	font-weight: var(--ui-font-weight-bold);
}

.product-item-label-ring.product-item-label-small {
	width: 40px;
	height: 40px;
	font-size: 14px;
	line-height: 40px;
}

.product-item-label-ring.product-item-label-big {
	width: 50px;
	height: 50px;
	font-size: 18px;
	line-height: 50px;
}

@media (max-width: 767px) {
	.product-item-label-ring.product-item-label-small,
	.product-item-scalable-card.hover .product-item-label-ring.product-item-label-small {
		width: 20px;
		height: 20px;
		font-size: 13px;
		line-height: 20px;
	}

	.product-item-big-card .product-item-label-ring.product-item-label-small,
	.product-item-label-ring.product-item-label-big,
	.product-item-scalable-card.hover .product-item-label-ring.product-item-label-big {
		width: 35px;
		height: 35px;
		font-size: 12px;
		line-height: 35px;
	}

	.product-item-big-card .product-item-label-ring.product-item-label-big {
		width: 70px;
		height: 70px;
		font-size: 24px;
		line-height: 70px;
	}
}

.product-item-label-top { top: -5px; }

.product-item-label-center {
	left: 50%;
	transform: translateX(-50%);
}

.product-item-label-center > div { text-align: center; }

.product-item-label-left { left: -5px; }

.product-item-label-left > div { text-align: left; }

.product-item-label-bottom { bottom: -5px; }

.product-item-label-middle {
	top: 50%;
	transform: translateY(-50%);
}

.product-item-label-right { right: -5px; }

.product-item-label-right > div { text-align: right; }

.product-item-label-center.product-item-label-middle { transform: translateY(-50%) translateX(-50%); }

/*endregion*/

/*region  Tabs */
.product-item-detail-tabs-container {
	min-height: 60px;
	border-bottom: 1px solid #ededed;
}

.product-item-detail-tabs-list {
	display: block;
	margin: 0;
	padding: 0;
	list-style: none;
}

.product-item-detail-tabs-list:after {
	display: table;
	clear: both;
	content: "";
}

.product-item-detail-tab {
	float: left;
	min-height: 60px;
}

.product-item-detail-tab-link {
	position: relative;
	display: inline-block;
	box-sizing: border-box;
	padding: 10px 25px;
	height: 60px;
	color: #000;
	vertical-align: middle;
	text-transform: uppercase;
	-ms-text-overflow: ellipsis;
	text-overflow: ellipsis;
	font-weight: var(--ui-font-weight-bold);
	font-size: 13px;
	line-height: 45px;
	transition: all 250ms ease;
}

.product-item-detail-tab-link:hover,
.product-item-detail-tab.active .product-item-detail-tab-link {
	color: #006cc0;
	color: var(--theme-color-primary);
	text-decoration: none;
}

.product-item-detail-tab-link:after {
	position: absolute;
	right: 0;
	bottom: 0;
	left: 0;
	height: 0;
	background: #006cc0;
	background: var(--theme-color-primary);
	content: "";
	transition: all 250ms ease;
}

.product-item-detail-tab-link:hover:after,
.product-item-detail-tab.active .product-item-detail-tab-link:after { height: 3px; }

.product-item-detail-tab-link:active:after { height: 6px; }

.product-item-detail-tab-content { padding: 25px 0; }

@media (max-width: 991px) {
	.product-item-detail-tabs-container { display: none !important; }

	.product-item-detail-tab-content { display: block !important; }
}

/*Tab fixed*/
.product-item-detail-tabs-container-fixed {
	position: fixed;
	top: -100px;
	right: 0;
	left: 0;
	z-index: 300;
	background-color: #fff;
	box-shadow: 0 4px 14px 0 rgba(32, 54, 74, .23);
	transition: top 350ms linear;
}

.product-item-detail-tabs-container-fixed {
	min-height: 45px;
	background: #fff;
}

.product-item-detail-tabs-container-fixed.active {
	top: 0;
	opacity: 1;
}

.product-item-detail-tabs-container-fixed .product-item-detail-tabs-list {
	margin: 0 auto;
	max-width: 1048px;
}

.product-item-detail-tabs-container-fixed .product-item-detail-tab { min-height: 45px; }

.product-item-detail-tabs-container-fixed .product-item-detail-tab-link {
	padding: 10px 25px;
	height: 45px;
	line-height: 25px;
}

.bx-touch .product-item-detail-tabs-container-fixed { display: none !important; }

/*endregion*/

/*region Small Card*/
.product-item-detail-short-card-fixed {
	position: fixed;
	top: -85px;
	right: 0;
	left: 0;
	z-index: 310;
	box-sizing: border-box;
	height: 85px;
	background-color: #fff;
	box-shadow: 0 4px 14px 0 rgba(32, 54, 74, .23);
	transition: top 220ms linear;
}

.product-item-detail-short-card-fixed.active {
	top: 0;
	opacity: 1;
}

.product-item-detail-short-card-fixed.active + .product-item-detail-tabs-container-fixed.active { top: 85px; }

.bx-touch .product-item-detail-short-card-fixed { display: none !important; }

.product-item-detail-short-card-fixed .product-item-detail-short-card-content-container {
	margin: 0 auto;
	max-width: 1048px;
	display: -webkit-box;
	display: -ms-flexbox;
	display: flex;
	width: 100%;
	-webkit-box-align: center;
	-ms-flex-align: center;
	align-items: center;
}

.product-item-detail-short-card-image {
	padding-right: 15px;
	width: 80px;
	max-width: 80px;
	vertical-align: middle;
	text-align: center;
}

.product-item-detail-short-card-image img {
	max-width: 100%;
	height: auto;
	max-height: 65px;
	width: auto;
}

.product-item-detail-short-title-container {
	max-width: 650px;
	-webkit-box-flex: 1;
	-ms-flex: 1;
	flex: 1;
}

.product-item-detail-short-title-text {
	display: block;
	color: #000;
	-ms-text-overflow: ellipsis;
	text-overflow: ellipsis;
	font-size: 16px;
	font-family: var(--ui-font-family-secondary, var(--ui-font-family-open-sans));
	font-weight: var(--ui-font-weight-regular, 400);
}

.product-item-detail-short-card-price {
	padding: 0 10px;
	vertical-align: middle;
	text-align: right;
}

.product-item-detail-short-card-btn {
	padding-left: 10px;
	min-width: 120px;
}

/*endregion*/

/*region Properties */
.product-item-detail-tab-content { display: none; }

.product-item-detail-tab-content.active { display: block; }

.product-item-detail-tab-content h3 {
	margin-bottom: 10px;
	font-weight: var(--ui-font-weight-bold);
	font-size: 14px;
}

.product-item-detail-properties {
	margin: 0;
	padding: 0;
	list-style: none;
	display: block;
}

.product-item-detail-properties-item {
	margin-bottom: 5px;
	display: flex;
	align-items: stretch;
}

.product-item-detail-properties-dots {
	border-bottom: 1px dotted #a5a5a5;
	-ms-flex-preferred-size: 0;
	flex-basis: 0;
	-ms-flex-positive: 1;
	flex-grow: 1;
	max-width: 100%;
}

.product-item-detail-properties-name,
.product-item-detail-properties-value {
	-ms-flex: 0 0 auto;
	flex: 0 0 auto;
	max-width: 50%;
	width: auto;
	font-size: 12px;
}

.product-item-detail-properties-name {
	padding-right: 3px;
	color: #a5a5a5;
}

.product-item-detail-properties-value {
	color: #343a40;
	padding-left: 3px;
	text-align: right;
}

/*endregion*/

.no-select {
	-webkit-touch-callout: none;
	-webkit-user-select: none;
	-moz-user-select: none;
	-ms-user-select: none;
	user-select: none;
}

input[type='number'] { -moz-appearance: textfield; }

input::-webkit-outer-spin-button,
input::-webkit-inner-spin-button { -webkit-appearance: none; }

/*стили бейджика товара*/
.tf-product-tag {
    padding: 0 8px;
    border: unset!important;
    border-radius: 4px;
    color: #000000; /* Дефолтный цвет текста */
    background-color: transparent; /* Дефолтный фон */
}

/* Цвета для бейджей */
.badge-hit {
    background-color: #E91E63; /* Красный для "Хит" */
    color: #FFFFFF; /* Белый текст */
    border-color: #E91E63; /* Граница под цвет фона */
}

.badge-sale {
    background-color: #4CAF50; /* Зеленый для "Акция" */
    color: #FFFFFF; /* Белый текст */
    border-color: #4CAF50; /* Граница под цвет фона */
}

.badge-new {
    background-color: #2196F3; /* Синий для "Новинка" */
    color: #FFFFFF; /* Белый текст */
    border-color: #2196F3; /* Граница под цвет фона */
}
/**блок с ценой */

.product-item-detail-price-old {
    margin-left: 10px;
    margin-right: 10px;
    }
.tf-product-info-price {
    margin-top: 5px;
}
.tf-product-info-price .badges-on-sale {  
    margin-left: 20px;
}

.tf-product-info-quantity .title {
    margin-top: 10px;
}
</style>
<script>
// Передаем ID раздела для AJAX
window.CATALOG_SECTION_ID = <?=intval($section['ID'])?>;
window.CATALOG_IBLOCK_ID = <?=intval($section['IBLOCK_ID'])?>;

// Передаем данные фильтров для JavaScript

window.FILTER_DATA = <?=json_encode($filterData)?>;

// Track added products
window.addedToCart = [];
window.addedToWishlist = [];
window.addedToCompare = [];

// Show popup notification
function showNotification(message, type = 'success') {
    const notification = document.createElement('div');
    notification.className = 'notification-popup ' + type;
    notification.innerHTML = `
        <div class="notification-content">
            <span class="notification-text">${message}</span>
            <button class="notification-close">&times;</button>
        </div>
    `;

    document.body.appendChild(notification);

    // Auto remove after 3 seconds
    setTimeout(() => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    }, 3000);

    // Close on click
    notification.querySelector('.notification-close').addEventListener('click', () => {
        if (notification.parentNode) {
            notification.parentNode.removeChild(notification);
        }
    });
}

function updateMiniCart() {
    fetch('/ajax/get_cart.php')
        .then(response => response.json())
        .then(data => {
            const cartItemsContainer = document.querySelector('.tf-mini-cart-items');
            const cartCountElement = document.querySelector('.nav-cart .count-box');
            const totalElement = document.querySelector('.tf-totals-total-value');
            const progressBar = document.querySelector('.tf-progress-bar .value');
            const progressText = document.querySelector('.text-caption-1');

            if (cartItemsContainer && data.items) {
                if (data.items.length > 0) {
                    let itemsHtml = '';
                    data.items.forEach(item => {
                        itemsHtml += `
                            <div class="tf-mini-cart-item file-delete">
                                <div class="tf-mini-cart-image">
                                    <img class="lazyload" data-src="${item.IMAGE}" src="${item.IMAGE}" alt="">
                                </div>
                                <div class="tf-mini-cart-info flex-grow-1">
                                    <div class="content">
                                        <div class="left">
                                            <div class="text-title"><a href="${item.URL}" class="link line-clamp-1">${item.NAME}</a></div>
                                            <div class="text-secondary-2">${item.DESCRIPTION}</div>
                                            <div class="wg-quantity">
                                                <span class="btn-quantity btn-decrease">-</span>
                                                <input type="text" class="quantity-product" name="number" value="${item.QUANTITY}">
                                                <span class="btn-quantity btn-increase">+</span>
                                            </div>
                                        </div>
                                        <div class="right">
                                            <div class="text-button tf-btn-remove remove">Удалить</div>
                                            <div class="text-button">${item.QUANTITY} X ${item.PRICE.toLocaleString('ru-RU')}₽</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
                    });
                    cartItemsContainer.innerHTML = itemsHtml;
                } else {
                    cartItemsContainer.innerHTML = '<div class="tf-mini-cart-item"><div class="text-center">Корзина пуста</div></div>';
                }

                // Update cart count
                if (cartCountElement) {
                    cartCountElement.textContent = data.total_quantity;
                    cartCountElement.style.display = data.total_quantity > 0 ? 'inline' : 'none';
                }

                // Update total price
                if (totalElement) {
                    totalElement.textContent = data.total_price.toLocaleString('ru-RU') + '₽';
                }

                // Update progress bar
                const freeShippingThreshold = 50000; // Assuming 50,000 rubles for free shipping
                if (progressBar && progressText) {
                    const progress = Math.min((data.total_price / freeShippingThreshold) * 100, 100);
                    progressBar.style.width = progress + '%';
                    if (data.total_price >= freeShippingThreshold) {
                        progressText.textContent = 'Поздравляем! Вы получаете бесплатную доставку!';
                    } else {
                        const remaining = freeShippingThreshold - data.total_price;
                        progressText.textContent = `До бесплатной доставки осталось ${remaining.toLocaleString('ru-RU')}₽`;
                    }
                }
            }
        })
        .catch(error => {
            console.error('Error updating mini cart:', error);
        });
}

function addToCart(productId, quantity = 1) {
    if (window.addedToCart.includes(productId)) {
        showNotification('Товар уже в корзине', 'info');
        return;
    }

    fetch('/ajax/add_to_cart.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'product_id=' + productId + '&quantity=' + quantity
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.addedToCart.push(productId);
            showNotification('Товар добавлен в корзину');

            // Update button text
            document.querySelectorAll(`.btn-main-product[data-product-id="${productId}"]`).forEach(btn => {
                btn.textContent = 'В корзине';
                btn.classList.add('in-cart');
            });

            // Update mini cart
            updateMiniCart();
        } else {
            showNotification(data.error || 'Ошибка добавления в корзину', 'error');
        }
    })
    .catch(error => {
        console.error('Error adding to cart:', error);
        showNotification('Ошибка добавления в корзину', 'error');
    });
}

function addToWishlist(productId) {
    fetch('/ajax/toggle_wishlist.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'product_id=' + productId
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.action === 'added') {
                window.addedToWishlist.push(productId);
                showNotification('Добавлено в избранное');
                document.querySelectorAll(`.wishlist[data-product-id="${productId}"]`).forEach(btn => {
                    btn.classList.add('active');
                });
            } else if (data.action === 'removed') {
                window.addedToWishlist = window.addedToWishlist.filter(id => id !== productId);
                showNotification('Удалено из избранного');
                document.querySelectorAll(`.wishlist[data-product-id="${productId}"]`).forEach(btn => {
                    btn.classList.remove('active');
                });
            }
        } else {
            showNotification(data.error || 'Ошибка при работе с избранным', 'error');
        }
    })
    .catch(error => {
        console.error('Error toggling wishlist:', error);
        showNotification('Ошибка при работе с избранным', 'error');
    });
}

function addToCompare(productId) {
    console.log('Нажатие на добавление в сравнение'); // Log when clicking add to compare
    fetch('/ajax/toggle_compare.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: 'product_id=' + productId + '&iblock_id=' + window.CATALOG_IBLOCK_ID
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (data.action === 'already_in_compare') {
                console.log('Товар уже в сравнении'); // Log item already in compare
                // Product already in compare - open modal
                const compareModal = new bootstrap.Modal(document.getElementById('compareModal'));
                compareModal.show();
            } else if (data.action === 'added') {
                console.log('Добавлено в сравнение'); // Log added to compare
                window.addedToCompare.push(productId);
                showNotification('Добавлено в сравнение');
                document.querySelectorAll(`.compare[data-product-id="${productId}"]`).forEach(btn => {
                    btn.classList.add('active');
                });
            } else if (data.action === 'removed') {
                console.log('Товар удален из сравнения'); // Log removed from compare (assuming toggle can remove)
                window.addedToCompare = window.addedToCompare.filter(id => id !== productId);
                showNotification('Удалено из сравнения');
                document.querySelectorAll(`.compare[data-product-id="${productId}"]`).forEach(btn => {
                    btn.classList.remove('active');
                });
            }
        } else {
            showNotification(data.error || 'Ошибка при работе со сравнением', 'error');
        }
    })
    .catch(error => {
        console.error('Error toggling compare:', error);
        showNotification('Ошибка при работе со сравнением', 'error');
    });
}

// Initialize cart state on page load
function initializeCartState() {
    fetch('/ajax/get_cart.php')
        .then(response => response.json())
        .then(data => {
            if (data.items && data.items.length > 0) {
                // Mark products that are already in cart
                data.items.forEach(item => {
                    window.addedToCart.push(item.ID.toString());
                    document.querySelectorAll(`.btn-main-product[data-product-id="${item.ID}"]`).forEach(btn => {
                        btn.textContent = 'В корзине';
                        btn.classList.add('in-cart');
                    });
                });
            }
            // Update mini cart regardless
            updateMiniCart();
        })
        .catch(error => {
            console.error('Error initializing cart state:', error);
        });
}

// Initialize wishlist state on page load
function initializeWishlistState() {
    fetch('/ajax/get_wishlist.php')
        .then(response => response.json())
        .then(data => {
            if (data.items && data.items.length > 0) {
                // Mark products that are already in wishlist
                data.items.forEach(item => {
                    window.addedToWishlist.push(item.ID.toString());
                    document.querySelectorAll(`.wishlist[data-product-id="${item.ID}"]`).forEach(btn => {
                        btn.classList.add('active');
                    });
                });
            }
        })
        .catch(error => {
            console.error('Error initializing wishlist state:', error);
        });
}

// Initialize compare state on page load
function initializeCompareState() {
    fetch('/ajax/get_compare.php?iblock_id=' + window.CATALOG_IBLOCK_ID)
        .then(response => response.json())
        .then(data => {
            if (data.items && data.items.length > 0) {
                // Mark products that are already in compare
                data.items.forEach(item => {
                    window.addedToCompare.push(item.ID.toString());
                    document.querySelectorAll(`.compare[data-product-id="${item.ID}"]`).forEach(btn => {
                        btn.classList.add('active');
                    });
                });
            }
        })
        .catch(error => {
            console.error('Error initializing compare state:', error);
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const tabMenuItems = document.querySelectorAll('.widget-menu-tab .item-title');
    const tabContents = document.querySelectorAll('.widget-content-inner');

    tabMenuItems.forEach((tab, index) => {
        tab.addEventListener('click', function() {
            // Remove active class from all tabs and contents
            tabMenuItems.forEach(item => item.classList.remove('active'));
            tabContents.forEach(content => content.classList.remove('active'));

            // Add active class to clicked tab and corresponding content
            this.classList.add('active');
            tabContents[index].classList.add('active');
        });
    });
// Add to cart (только для основного товара)
document.querySelectorAll('.btn-main-product.main-product').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            const quantityInput = document.getElementById(productId + '_quantity');
            const quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
            addToCart(productId, quantity);
        });
    });
    // Add to wishlist (только для основного товара)
    document.querySelectorAll('.wishlist.main-product').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const productId = this.getAttribute('data-product-id');
            addToWishlist(productId);
        });
    });
    // Add to compare (только для основного товара)
    document.querySelectorAll('.compare.main-product').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            if (this.hasAttribute('data-bs-toggle') && this.getAttribute('data-bs-toggle') === 'modal') {
                return;
            }
            const productId = this.getAttribute('data-product-id');
            console.log('Clicked compare button for productId: ' + productId);
            fetch('/log.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: 'log=Clicked compare button for productId: ' + productId
            }).catch(err => console.error('Log send error:', err));
            addToCompare(productId);
        });
    });
    // Handle sorting dropdown
    const sortItems = document.querySelectorAll('.select-item[data-sort-value]');
    const sortValueSpan = document.querySelector('.text-sort-value');

    sortItems.forEach(function(item) {
        item.addEventListener('click', function() {
            const sortValue = this.getAttribute('data-sort-value');
            const sortText = this.querySelector('.text-value-item').textContent;

            // Update the displayed sort text
            if (sortValueSpan) {
                sortValueSpan.textContent = sortText;
            }

            // Remove active class from all items
            sortItems.forEach(function(si) {
                si.classList.remove('active');
            });

            // Add active class to selected item
            this.classList.add('active');

            // Update URL with sort parameter
            const url = new URL(window.location);
            url.searchParams.set('sort', sortValue);
            window.location.href = url.toString();
        });
    });

    // Set active state based on current URL parameter
    const currentSort = new URLSearchParams(window.location.search).get('sort') || 'best-selling';
    const activeItem = document.querySelector('.select-item[data-sort-value="' + currentSort + '"]');
    if (activeItem) {
        activeItem.classList.add('active');
        if (sortValueSpan) {
            sortValueSpan.textContent = activeItem.querySelector('.text-value-item').textContent;
        }
    }

    // Show bestseller items by removing inline display:none

    const bestsellerItems = document.querySelectorAll('.bestseller-item');

    bestsellerItems.forEach(function(item) {

        item.style.display = 'block';

    });

    // Initialize cart state
    initializeCartState();

    // Initialize wishlist state
    initializeWishlistState();

    // Initialize compare state
    initializeCompareState();

    // Add to cart
/*
   // Обновленный обработчик для добавления в корзину
document.querySelectorAll('.btn-main-product').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const productId = this.getAttribute('data-product-id');
        console.log('Нажатие на добавление в корзину для продукта:', productId);
        
        // Всегда берем из основного input
        const quantityInput = document.getElementById(productId + '_quantity');
        const quantity = quantityInput ? parseInt(quantityInput.value) || 1 : 1;
        
        console.log('Количество из input:', quantity);
        addToCart(productId, quantity);
    });
});

    // Add to wishlist

    document.querySelectorAll('.wishlist').forEach(function(btn) {

        btn.addEventListener('click', function() {

            const productId = this.getAttribute('data-product-id');

            addToWishlist(productId);

        });

    });

   // Add to compare
document.querySelectorAll('.compare').forEach(function(btn) {
    btn.addEventListener('click', function(e) {
        // Если кнопка имеет data-bs-toggle="modal", позволяем открыть модальное окно (не добавляем в сравнение)
        if (this.hasAttribute('data-bs-toggle') && this.getAttribute('data-bs-toggle') === 'modal') {
            return; // Не перехватываем клик, модальное окно откроется
        }
        
        const productId = this.getAttribute('data-product-id');
        console.log('Clicked compare button for productId: ' + productId);  // Лог в консоль браузера
        // Отправка лога в файл
        fetch('/log.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'log=Clicked compare button for productId: ' + productId
        }).catch(err => console.error('Log send error:', err));
        
        addToCompare(productId);
    });
});
*/

});

// Устанавливаем динамический productId
window.productId = <?=$element['ID']?>;

// Флаг для предотвращения дублирования обработчика
if (!window.quantityHandlersAdded) {
    window.quantityHandlersAdded = true;

    document.addEventListener('click', function(e) {
        // Для кнопки плюс
        if (e.target.classList.contains('plus-btn')) {
            e.stopImmediatePropagation();
            const wgQuantity = e.target.closest('.wg-quantity');
            if (wgQuantity) {
                const input = wgQuantity.querySelector('input[name="number"]');
                if (input) {
                    let value = parseInt(input.value) || 1;
                    input.value = value + 1;
                    console.log('Plus clicked in', wgQuantity.className, 'new value:', input.value);

                    // Синхронизация: обновляем оба input
                    const productId = window.productId;
                    const mainInput = document.getElementById(productId + '_quantity');
                    const stickyInput = document.querySelector('.tf-sticky-atc-infos input[name="number"]');
                    if (mainInput) mainInput.value = input.value;
                    if (stickyInput) stickyInput.value = input.value;
                }
            }
        }

        // Для кнопки минус
        if (e.target.classList.contains('minus-btn')) {
            e.stopImmediatePropagation();
            const wgQuantity = e.target.closest('.wg-quantity');
            if (wgQuantity) {
                const input = wgQuantity.querySelector('input[name="number"]');
                if (input) {
                    let value = parseInt(input.value) || 1;
                    if (value > 1) {
                        input.value = value - 1;
                        console.log('Minus clicked in', wgQuantity.className, 'new value:', input.value);

                        // Синхронизация
                        const productId = window.productId;
                        const mainInput = document.getElementById(productId + '_quantity');
                        const stickyInput = document.querySelector('.tf-sticky-atc-infos input[name="number"]');
                        if (mainInput) mainInput.value = input.value;
                        if (stickyInput) stickyInput.value = input.value;
                    }
                }
            }
        }
    }, true);
}


</script>
