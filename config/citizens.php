<?php

declare(strict_types=1);

/**
 * Mock KTP Digital Global — 41 warga kota / mahasiswa untuk End-User SSO.
 * Password default lab: KtpDigital2026!
 */
$defaultPassword = 'KtpDigital2026!';

$names = [
    'Ahmad Rizki Pratama', 'Budi Santoso', 'Citra Dewi Lestari', 'Dian Permata Sari',
    'Eko Wijaya Nugroho', 'Fitri Handayani', 'Galih Mahendra', 'Hana Kirana Putri',
    'Indra Kusuma', 'Joko Susilo', 'Kartika Anggraini', 'Lukman Hakim',
    'Maya Sari Dewi', 'Nadia Putri Rahayu', 'Oki Pratama', 'Putri Ayu Lestari',
    'Qori Sandiarta', 'Rizka Amelia', 'Siti Nurhaliza', 'Teguh Wicaksono',
    'Umar Faruq', 'Vina Melati', 'Wulan Dari', 'Xavier Gunawan',
    'Yuni Astuti', 'Zahra Kamila', 'Aditya Nugroho', 'Bayu Setiawan',
    'Candra Wijaya', 'Dewi Lestari', 'Erlangga Putra', 'Farah Maulida',
    'Guntur Prasetyo', 'Hesti Wulandari', 'Irfan Saputra', 'Jessica Tanaya',
    'Kevin Ardiansyah', 'Larasati Putri', 'Muhammad Fadli', 'Nabila Syafira',
    'Omar Hakim',
];

$citizens = [];

foreach ($names as $index => $name) {
    $num = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
    $email = "warga{$num}@ktp.iae.id";
    $nim = '2026' . str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);

    $citizens[$email] = [
        'password' => $defaultPassword,
        'name' => $name,
        'nim' => $nim,
        'email' => $email,
    ];
}

return $citizens;
