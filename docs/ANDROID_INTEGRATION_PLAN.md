# SMA-MOVE — ANDROID INTEGRATION PLAN

**Plan Date:** November 4, 2026  
**Architect:** Android Integration Architect & Mobile Security Lead (Antigravity)  
**Target Project:** `C:\Users\SCAD LAB\StudioProjects\sma-move-android`  
**Package:** `com.smamove.android`  
**Framework:** Kotlin + Jetpack Compose (BOM 2026.02.01)  

---

## 1. Existing Android Project Audit

The SMA-Move Android application has been inspected on the local machine. It represents a mature, accessibility-conscious UI designed with Material 3 and custom high-contrast color themes for individuals with Spinal Muscular Atrophy.

### 1.1 Technical Stack
- **Language**: Kotlin 2.2.10
- **UI Toolkit**: Jetpack Compose (Compose BOM 2026.02.01)
- **Architecture**: Single-Activity navigation model (`MainActivity.kt` + `AppNavigation.kt`)
- **Min SDK**: 26 (Android 8.0 Oreo)
- **Target SDK**: 37
- **Biometrics**: `androidx.biometric:1.1.0` integrated for local unlock

### 1.2 Existing Prototype / Demo Implementations Identified
The following components currently use simulated in-memory data that will be connected to the real PHP backend:

| File | Current Prototype Implementation | Backend Integration Target |
| :--- | :--- | :--- |
| `feature/auth/AuthRepository.kt` | Hardcoded validation (`demo@smamove.com` / `password`, `SMA-1234` code) | Real Retrofit calls to `/api/auth/login.php`, `signup.php`, `refresh_token.php` |
| `feature/auth/AuthSessionStore.kt` | Standard unencrypted `SharedPreferences` storing only role string | `EncryptedSharedPreferences` backed by Android Keystore storing Bearer token, refresh token & user metadata |
| `feature/careplan/CarePlanViewModel.kt`| Hardcoded in-memory list of 5 tasks | Fetch from `/api/patient/care_plans.php`, toggle completion with persistence |
| `feature/caregiver/CaregiverModel.kt` | Static in-memory `CaregiverStore.caregivers` | Fetch linked caregivers from `/api/caregiver/patients.php` or `/api/patient/profile.php` |
| `feature/journal/JournalViewModel.kt` | In-memory `mutableStateListOf<JournalEntry>` | Persist and fetch entries via `/api/patient/wellbeing.php` |
| `feature/movetrack/MoveTrackConsentStore.kt` | Local `SharedPreferences` | Synchronize consent preferences with `/api/consent/` endpoints |
| `feature/appointments/AppointmentDetailsScreen.kt` | Hardcoded specialist and appointment details | Fetch real scheduled appointments from `/api/patient/appointments.php` |

---

## 2. Integration Architecture

The Android integration will follow standard Clean Architecture and Android Jetpack guidelines without altering existing Compose UI layouts, theming, or user experience.

```
com.smamove.android/
│
├── core/
│   ├── network/
│   │   ├── ApiClient.kt             # Retrofit & OkHttpClient factory
│   │   ├── AuthInterceptor.kt       # Appends Bearer token to requests
│   │   ├── TokenAuthenticator.kt    # Automatic token refresh on HTTP 401
│   │   ├── NetworkResult.kt         # Standard Sealed class (Success, Error, Loading)
│   │   └── NetworkConfig.kt         # Dynamic Base URL resolution (Emulator vs LAN vs Prod)
│   │
│   ├── storage/
│   │   └── SecureSessionManager.kt  # Android Keystore encrypted token storage
│   │
│   └── model/                       # Data Transfer Objects (DTOs) matching backend JSON
│       ├── ApiResponseDto.kt
│       ├── AuthDtos.kt
│       ├── CarePlanDtos.kt
│       ├── AppointmentDtos.kt
│       ├── WellbeingDtos.kt
│       ├── ConsentDtos.kt
│       └── MoveTrackDtos.kt
│
├── feature/
│   ├── auth/
│   │   ├── AuthRepository.kt        # Updated to call real AuthApiService
│   │   └── AuthViewModel.kt         # Exposes StateFlow for UI consumption
│   │
│   ├── patient/
│   │   └── PatientRepository.kt     # Real network operations for patient dashboard
│   │
│   ├── careplan/
│   │   └── CarePlanViewModel.kt     # Updated to observe real network tasks
│   │
│   ├── caregiver/
│   │   └── CaregiverRepository.kt   # Calls /api/caregiver endpoints
│   │
│   ├── professional/
│   │   └── ProfessionalRepository.kt# Calls /api/professional endpoints
│   │
│   └── movetrack/
│       └── MoveTrackRepository.kt   # Submits session metadata to backend
```

---

## 3. Network Configuration for Local Development

### 3.1 Network Security Configuration
Android 9.0 (API level 28+) blocks cleartext HTTP traffic by default. For local development with Apache over HTTP, a debug network security config will be added:

`app/src/main/res/xml/network_security_config.xml`:
```xml
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="true">10.0.2.2</domain>
        <domain includeSubdomains="true">127.0.0.1</domain>
        <domain includeSubdomains="true">172.23.51.176</domain>
    </domain-config>
</network-security-config>
```

### 3.2 Dynamic Base URL Resolution
- **Android Emulator**: `http://10.0.2.2/sma-move-backend/api/`
- **Physical Device on Same Wi-Fi/LAN**: `http://172.23.51.176/sma-move-backend/api/`
- **Configurable**: Configured in a central `NetworkConfig.kt` file, allowing easy switching without modifying UI code.

---

## 4. Dependencies to Add (Phase 7)

In `gradle/libs.versions.toml`:
```toml
[versions]
retrofit = "2.11.0"
okhttp = "4.12.0"
securityCrypto = "1.1.0-alpha06"

[libraries]
retrofit-core = { group = "com.squareup.retrofit2", name = "retrofit", version.ref = "retrofit" }
retrofit-converter-gson = { group = "com.squareup.retrofit2", name = "converter-gson", version.ref = "retrofit" }
okhttp-core = { group = "com.squareup.okhttp3", name = "okhttp", version.ref = "okhttp" }
okhttp-logging = { group = "com.squareup.okhttp3", name = "logging-interceptor", version.ref = "okhttp" }
androidx-security-crypto = { group = "androidx.security", name = "security-crypto", version.ref = "securityCrypto" }
```

In `app/build.gradle.kts`:
```kotlin
dependencies {
    // Networking
    implementation(libs.retrofit.core)
    implementation(libs.retrofit.converter.gson)
    implementation(libs.okhttp.core)
    implementation(libs.okhttp.logging)

    // Keystore-backed encrypted preferences
    implementation(libs.androidx.security.crypto)
}
```

---

## 5. Non-Destructive Integration Protocol

To ensure 100% preservation of existing UI and user experience:
1. **Zero UI Redesigns**: Do not touch the Compose layout hierarchies, button styles, animations (`SmaMotion.kt`), or accessibility settings (`AccessibilitySettingsScreen.kt`).
2. **Preserve Navigation Graph**: All route names in `Routes.kt` remain unchanged.
3. **Repository Abstraction**: UI composables observe ViewModels as they currently do. ViewModels receive data from the updated Repository layer, meaning UI screens receive the same data types and contracts.
4. **Graceful Loading & Error Feedback**: Maintain existing Compose loading states, Snackbars, and dialog notifications for network failures.
5. **No Direct MariaDB Access**: Android connects solely through the secure REST API over HTTP/JSON.
