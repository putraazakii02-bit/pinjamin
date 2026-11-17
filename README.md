
# Pinjam[in] - Peminjaman Barang Antar Mahasiswa

Pinjam[in] merupakan aplikasi peminjaman barang antar mahasiswa yang memudahkan pengguna menawarkan, mencari, dan meminjam barang tanpa harus membelinya.

## Tujuan Aplikasi

Menyediakan sistem peminjaman barang yang terstruktur, mulai dari pengajuan, negosiasi, kesepakatan, pembayaran, hingga pengembalian barang.

## Teknologi yang Digunakan

- **Frontend:** Flutter dan Dart
- **Backend/API:** Laravel REST API
- **Database:** MySQL
- **Komunikasi Real-time:** Laravel Reverb (WebSocket)
- **Version Control:** Git dan GitHub

## Struktur Repository

```text
pinjamin/
├── frontend/    # Aplikasi Flutter
├── api/         # Backend dan REST API Laravel
├── database/    # Skrip SQL, ERD, dan dokumentasi database
└── README.md
```

## Fitur Utama

1. Registrasi, login, dan identitas mahasiswa.
2. Katalog, pencarian, dan pengelolaan barang.
3. Pengajuan dan negosiasi peminjaman.
4. Chat antar pengguna secara real-time.
5. Kesepakatan dan pencatatan transaksi.
6. Pembayaran manual di luar aplikasi.
7. Konfirmasi penyerahan dan pengembalian barang.
8. Pengingat keterlambatan dan pengajuan perpanjangan.
9. Notifikasi dan riwayat transaksi.
10. Pelaporan pengguna.

## Sistem Pembayaran

Pembayaran dilakukan secara manual di luar aplikasi melalui metode yang disepakati kedua pihak. Aplikasi digunakan untuk mencatat harga, status pembayaran, dan konfirmasi penerimaan pembayaran.

## Status Proyek

Proyek sedang dalam tahap persiapan dan pengembangan secara bertahap.
