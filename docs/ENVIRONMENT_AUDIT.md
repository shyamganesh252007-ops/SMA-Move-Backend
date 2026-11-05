# SMA-MOVE — ENVIRONMENT AUDIT REPORT

**Audit Date:** November 4, 2026  
**Auditor:** Senior Backend & Integration Architect (Antigravity)  
**Project:** SMA-Move (Privacy-First Healthcare Support Platform)  
**Target Environment:** Local Development (Apache + PHP 8.2 + MariaDB + Jetpack Compose Android)  

---

## 1. Executive Summary

This environment audit evaluates the local development and runtime infrastructure for the **SMA-Move** healthcare platform backend and its integration with the existing Android frontend.

The audit verified active running services, runtime versions, available extensions, network reachability, and directory structures. All critical server components (Apache HTTP Server, MariaDB 10.4, and PHP 8.2 with PDO MySQL) are operational.

---

## 2. Infrastructure & Runtime Specifications

| Component | Verified Specification | Status | Evidence / Notes |
| :--- | :--- | :--- | :--- |
| **Operating System** | Windows 10/11 Pro (x64) | **VERIFIED** | PowerShell environment running on `SCAD LAB` host |
| **Local Web Server** | Apache/2.4.58 (Win64) OpenSSL/3.1.3 | **ACTIVE** | Process `httpd.exe` (PIDs: 9056, 16664) listening on port 80 |
| **Database Server** | MariaDB 10.4.32 | **ACTIVE** | Process `mysqld.exe` (PID: 3320) listening on port 3306 |
| **PHP Runtime** | PHP 8.2.12 (ZTS Visual C++ 2019 x64) | **VERIFIED** | Located at `C:\xampp\php\php.exe` |
| **Web Server Root** | `C:\xampp\htdocs\` | **VERIFIED** | HTTP 200 OK on `http://localhost/` |
| **Backend Directory**| `C:\xampp\htdocs\sma-move-backend` | **VERIFIED** | HTTP 200 OK on `http://localhost/sma-move-backend/` |
| **Database Name** | `sma_move` | **VERIFIED** | Present in MariaDB, utf8mb4_unicode_ci collation |
| **Android Project** | `C:\Users\SCAD LAB\StudioProjects\sma-move-android` | **VERIFIED** | Package: `com.smamove.android`, Kotlin 2.2.10, Jetpack Compose BOM 2026.02.01 |

---

## 3. Web Server & Apache Configuration

### 3.1 Apache Module Inspection
- **`mod_rewrite`**: **VERIFIED ENABLED**. Found in `C:\xampp\apache\conf\httpd.conf` at line 163 (`LoadModule rewrite_module modules/mod_rewrite.so`).
- **`AllowOverride`**: **VERIFIED ENABLED** (`AllowOverride All` configured for `C:/xampp/htdocs` at line 273). This allows custom `.htaccess` routing rules for clean REST API endpoints (e.g. `/api/auth/login`).
- **Virtual Host / Direct Path**: Serving directly from standard XAMPP document root:
  - Base Local URL: `http://localhost/sma-move-backend/api/`
  - Base Host Emulator URL: `http://10.0.2.2/sma-move-backend/api/`
  - Base LAN Physical Device URL: `http://172.23.51.176/sma-move-backend/api/`

---

## 4. PHP Runtime & Extensions Audit

Executing `C:\xampp\php\php.exe -m` verified the following critical modules:

| Extension | Required For | Verification Result |
| :--- | :--- | :--- |
| **`PDO`** | Core PHP Data Objects abstraction | **LOADED** |
| **`pdo_mysql`** | Native MariaDB/MySQL PDO driver | **LOADED** |
| **`openssl`** | Cryptographic token generation & encryption | **LOADED** |
| **`mbstring`** | Multibyte string sanitization & validation | **LOADED** |
| **`json`** | API request parsing & response serialization | **LOADED** |
| **`hash`** | SHA-256 token hashing & cryptographic MAC | **LOADED** |
| **`random`** | CSPRNG (`random_bytes()`, `bin2hex()`) | **LOADED** |
| **`curl`** | Outbound HTTP requests / verification integrations | **LOADED** |
| **`session`** | Fallback session state / token lifecycle | **LOADED** |

**Note on System PATH**: `php` is not currently registered in the global Windows system `%PATH%` environment variable, but is fully operational via its absolute path: `C:\xampp\php\php.exe`. Scripts and automated tasks will execute against this explicit path.

---

## 5. MariaDB Connectivity Audit

Direct database connection testing via PHP PDO was performed:
```
mysql:host=127.0.0.1;port=3306;dbname=sma_move;charset=utf8mb4
User: root
Password: [empty / default XAMPP]
Result: Connected successfully.
Database: sma_move
Engine: InnoDB
Server Version: 10.4.32-MariaDB
```

- Connection latency is minimal (< 2ms on loopback).
- Database credentials will be abstracted to `.env` / configuration files with restrictive permissions.

---

## 6. Android Project Audit

- **Project Location**: `C:\Users\SCAD LAB\StudioProjects\sma-move-android`
- **Application ID / Package**: `com.smamove.android`
- **Android Target**: `compileSdk = 37`, `targetSdk = 37`, `minSdk = 26`
- **Compose Version**: Compose BOM `2026.02.01`
- **Current Network State**: The Android app currently relies on fake in-memory repositories (e.g. `AuthRepository.kt` checking hardcoded strings `"demo@smamove.com"` / `"password"`). Retrofit and OkHttp dependencies are ready to be declared in `libs.versions.toml` and `app/build.gradle.kts`.

---

## 7. Verified Facts vs. Working Assumptions

### Verified Facts (Ground Truth)
1. Apache and MariaDB services are running in XAMPP on ports 80 and 3306.
2. PHP 8.2.12 has all required extensions (`pdo_mysql`, `openssl`, `mbstring`, `json`, `hash`).
3. Database `sma_move` exists and is accessible.
4. `mod_rewrite` is enabled and `AllowOverride All` is active on `htdocs`.
5. The Android project exists and compiles against Android SDK 37 and Compose BOM 2026.02.01.

### Working Assumptions (To Be Resolved in Subsequent Phases)
1. In local development, the root MariaDB account has no password; for production, dedicated least-privilege credentials will be provisioned.
2. Android Emulator will connect via `http://10.0.2.2/sma-move-backend/api/`, while physical testing devices will connect via `http://172.23.51.176/sma-move-backend/api/` with Android cleartext network security configuration enabled for local testing.
