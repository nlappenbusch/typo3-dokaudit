<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'DokAudit – barrierefreie PDFs',
    'description' => 'Prüft PDFs in der Dateiliste auf Barrierefreiheit (PDF/UA, WCAG, BITV) und ersetzt sie durch die von DokAudit korrigierte Fassung. Links bleiben gleich, Originale werden gesichert.',
    'category' => 'be',
    'author' => 'Nils Lappenbusch',
    'author_email' => 'kontakt@dokaudit.de',
    'author_company' => 'DokAudit',
    'state' => 'stable',
    'version' => '1.2.2',
    'constraints' => [
        'depends' => [
            'typo3' => '12.4.0-13.4.99',
        ],
        'suggests' => [
            'scheduler' => '',
        ],
    ],
];
