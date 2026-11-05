# SMA-MOVE — PHASE 6.2: ANDROID NETWORKING FOUNDATION IMPLEMENTATION REPORT

**Date:** 2026-11-04  
**Project:** SMA-Move — A privacy-first healthcare support platform for Spinal Muscular Atrophy  
**Android Workspace:** `C:\Users\SCAD LAB\StudioProjects\sma-move-android` (`com.smamove.android`)  
**Backend Workspace:** `C:\xampp\htdocs\sma-move-backend`  
**Status:** Completed & Verified (100% Tests Passing, Build Succeeded)  

---

## 1. Executive Summary

Phase 6.2 establishes a robust, type-safe, privacy-preserving HTTP networking and secure credential storage foundation for the SMA-Move Android application. 

### Key Accomplishments:
1. **Modern Networking Stack Configured:** Configured Square Retrofit `2.11.0`, OkHttp `4.12.0`, Kotlinx Serialization `1.7.3`, and AndroidX Security Crypto `1.1.0-alpha06` without version conflicts.
2. **Environment-Aware Base URL Engine (`ApiConfig`):** Automatic default to local development emulator (`http://10.0.2.2/sma-move-backend/api/`) in debug and HTTPS in release, with dynamic runtime overrides for local physical device LAN testing (`http://<LAN_IP>/...`) and strict URL trailing-slash normalization.
3. **Hardware Keystore-Backed Secure Persistence (`EncryptedTokenStorage`):** Replaced proposed unencrypted session storage with an Android Keystore implementation utilizing `AES-256-GCM` for value encryption and `AES-256-SIV` for key determinism. Plaintext JWT tokens are never written to disk.
4. **Resilient Network Foundation & Error Handling:** Built generic response envelope `ApiResponse<T>`, sealed UI state container `NetworkResult<T>`, and `ApiErrorParser` mapping HTTP 400, 401, 403, 404, 409, 422, and 500 error payloads into user-friendly messages with field-level validation breakdowns.
5. **Secure Authentication Interceptor (`AuthInterceptor`):** Automates Bearer token injection for protected endpoints, strictly exempts public authentication endpoints (`/auth/login.php`, `/auth/signup.php`, `/auth/forgot_password.php`, `/auth/reset_password.php`, `/auth/verify_email.php`), redacts sensitive credentials from logs, and captures HTTP 401 to clear local tokens and trigger session expiration callbacks.
6. **Network Security Configuration:** Implemented `network_security_config.xml` permitting cleartext communication exclusively for `10.0.2.2`, `localhost`, and `127.0.0.1` during local development while enforcing strict HTTPS for all external domains.
7. **Complete DTO Foundation & Retrofit Contract:** Implemented verified DTOs for Auth, Care Plans, Appointments, Wellbeing Journals, MoveTrack minimal metadata, and Caregiver coordination, coupled with a central `ApiService` interface.
8. **100% Test Pass & Clean Build:** Implemented unit tests exercising URL normalization, MockWebServer token injection, public endpoint bypass, 401 callback triggering, error parsing, and JSON serialization. Executed both `testDebugUnitTest` and `assembleDebug` with zero failures.

---

## 2. Dependency & Build Configuration

All networking and serialization libraries were integrated using the Gradle Version Catalog (`libs.versions.toml`) to preserve modular dependency management and avoid conflicts with existing Compose libraries.

### 2.1 Version Catalog (`gradle/libs.versions.toml`)
```toml
[versions]
retrofit = "2.11.0"
okhttp = "4.12.0"
kotlinxSerialization = "1.7.3"
securityCrypto = "1.1.0-alpha06"

[libraries]
retrofit-core = { group = "com.squareup.retrofit2", name = "retrofit", version.ref = "retrofit" }
retrofit-converter-kotlinx-serialization = { group = "com.squareup.retrofit2", name = "converter-kotlinx-serialization", version.ref = "retrofit" }
okhttp-core = { group = "com.squareup.okhttp3", name = "okhttp", version.ref = "okhttp" }
okhttp-logging = { group = "com.squareup.okhttp3", name = "logging-interceptor", version.ref = "okhttp" }
kotlinx-serialization-json = { group = "org.jetbrains.kotlinx", name = "kotlinx-serialization-json", version.ref = "kotlinxSerialization" }
androidx-security-crypto = { group = "androidx.security", name = "security-crypto", version.ref = "securityCrypto" }

[plugins]
kotlin-serialization = { id = "org.jetbrains.kotlin.plugin.serialization", version.ref = "kotlin" }
```

### 2.2 App Module Build Configuration (`app/build.gradle.kts`)
- Applied plugin `alias(libs.plugins.kotlin.serialization)`.
- Enabled `buildFeatures { buildConfig = true }` and `testOptions { unitTests.isReturnDefaultValues = true }`.
- Injected `API_BASE_URL` build config field:
  - Debug: `"http://10.0.2.2/sma-move-backend/api/"`
  - Release: `"https://api.smamove.org/api/"`
- Added dependencies for Retrofit, OkHttp, Kotlinx Serialization, Security Crypto, MockWebServer, and Coroutines Test.

---

## 3. Network Security & Manifest Configuration

### 3.1 Network Security Configuration (`app/src/main/res/xml/network_security_config.xml`)
Android 9.0+ (API 28+) disables cleartext HTTP by default. To enable seamless local testing against XAMPP on the host machine without disabling system-wide cleartext protections:
```xml
<?xml version="1.0" encoding="utf-8"?>
<network-security-config>
    <domain-config cleartextTrafficPermitted="true">
        <domain includeSubdomains="true">10.0.2.2</domain>
        <domain includeSubdomains="true">localhost</domain>
        <domain includeSubdomains="true">127.0.0.1</domain>
    </domain-config>
    <base-config cleartextTrafficPermitted="false" />
</network-security-config>
```

### 3.2 Manifest Update (`app/src/main/AndroidManifest.xml`)
- Declared required permissions:
  - `<uses-permission android:name="android.permission.INTERNET" />`
  - `<uses-permission android:name="android.permission.ACCESS_NETWORK_STATE" />`
- Attached security configuration to application tag: `android:networkSecurityConfig="@xml/network_security_config"`.

---

## 4. Secure Persistence Architecture

The backend issues short-lived JWT access tokens and refresh tokens. To safeguard patient health information and prevent token theft:

### 4.1 `TokenStorage.kt`
Interface abstracting all token and session lifecycle operations:
- `saveTokens(accessToken, refreshToken)`
- `getAccessToken()`, `getRefreshToken()`
- `hasValidToken()`
- `clearTokens()`
- `saveUserSession(userId, email, role, fullName)`
- `getUserId()`, `getUserEmail()`, `getUserRole()`, `getUserFullName()`
- `clearSession()`

### 4.2 `EncryptedTokenStorage.kt`
Implements `TokenStorage` using AndroidX Security Crypto:
- Generates master key in Android Keystore with `KeyGenParameterSpec` (`MasterKey.KeyScheme.AES256_GCM`).
- Encrypts keys using `AES256_SIV` and values using `AES256_GCM`.
- Provides fallback for JVM unit testing environments where Android Keystore is unavailable.

### 4.3 `InMemoryTokenStorage.kt`
Provides a memory-backed implementation of `TokenStorage` specifically designed for JUnit test suites, preview environments, and guest sessions.

---

## 5. Networking Core Components

```
┌────────────────────────────────────────────────────────┐
│                   ApiService                           │
│  (Retrofit Interface + Kotlinx Serialization JSON)     │
└───────────────────────────▲────────────────────────────┘
                            │
┌───────────────────────────┴────────────────────────────┐
│                  ApiClient Factory                     │
│  - 15s Connect/Read/Write Timeouts                     │
│  - Retry on Connection Failure                         │
│  - Sanitized HttpLoggingInterceptor                    │
└───────────────────────────▲────────────────────────────┘
                            │
┌───────────────────────────┴────────────────────────────┐
│                  AuthInterceptor                       │
│  - Bearer Token Injection                              │
│  - Public Endpoint Bypass                              │
│  - 401 Session Expiration Hook                         │
└───────────────────────────▲────────────────────────────┘
                            │
┌───────────────────────────┴────────────────────────────┐
│             TokenStorage / Keystore                     │
└────────────────────────────────────────────────────────┘
```

### 5.1 Base URL Management (`ApiConfig.kt`)
- Standardizes trailing slashes so relative Retrofit paths resolve correctly.
- Prevents invalid protocols (rejects non-HTTP schemes).
- Allows runtime updates (`setCustomBaseUrl`) for LAN testing on physical devices without code changes.

### 5.2 Envelope & Result Hierarchy (`ApiResponse.kt` & `NetworkResult.kt`)
- `ApiResponse<T>`: Mirrors standard PHP backend envelope:
  ```kotlin
  @Serializable
  data class ApiResponse<T>(
      @SerialName("success") val success: Boolean,
      @SerialName("message") val message: String = "",
      @SerialName("data") val data: T? = null
  )
  ```
- `NetworkResult<T>`: Sealed interface (`Success<T>`, `Error`, `Loading`) that decouples HTTP response parsing from Compose ViewModels and UI state.

### 5.3 Error Parser (`ApiErrorParser.kt`)
- Inspects HTTP error bodies and parses standard error envelopes with field-level validation breakdowns (e.g., `Validation failed (email: Invalid email format; password: Password too short)`).
- Maps raw network exceptions (`ConnectException`, `SocketTimeoutException`, `SerializationException`) into friendly, accessible status messages.

### 5.4 Token Injection & Public Bypass (`AuthInterceptor.kt`)
- Attaches `Authorization: Bearer <token>` to all protected endpoints.
- Maintains strict whitelist of public endpoints that bypass authentication:
  - `/auth/login.php`
  - `/auth/signup.php`
  - `/auth/forgot_password.php`
  - `/auth/reset_password.php`
  - `/auth/verify_email.php`
- Detects HTTP 401 on protected requests, invalidates local tokens, and invokes the `onSessionExpired` application callback.

### 5.5 OkHttp & Retrofit Factory (`ApiClient.kt`)
- Sets 15-second connect, read, and write timeouts.
- Configures Kotlinx Serialization with `ignoreUnknownKeys = true`, `isLenient = true`, `encodeDefaults = true`, and `coerceInputValues = true`.
- Implements redacted logging: matches and replaces `Bearer <token>` and `"password":"..."` with `[REDACTED]`.

---

## 6. Verified DTOs & Retrofit Interface

All verified endpoints in the PHP backend are represented by Kotlin `@Serializable` data classes in `com.smamove.android.core.network.dto`:

| Category | DTO File | Key Models |
| :--- | :--- | :--- |
| **Authentication** | `AuthDto.kt` | `LoginRequest`, `SignupRequest`, `AuthTokensData`, `UserData`, `LoginData` |
| **Appointments** | `AppointmentDto.kt` | `AppointmentData`, `CreateAppointmentRequest`, `UpdateAppointmentStatusRequest` |
| **Care Plan** | `CareTaskDto.kt` | `CareTaskData`, `CareTaskStepData`, `ToggleTaskRequest`, `ToggleTaskData` |
| **Wellbeing Journal** | `JournalDto.kt` | `JournalEntryData`, `CreateJournalRequest`, `CreateJournalResponseData` |
| **MoveTrack** | `MoveTrackDto.kt` | `StartSessionRequest`, `StartSessionData`, `CompleteSessionRequest`, `MoveTrackSessionData` |
| **Consent** | `ConsentDto.kt` | `ConsentItemData`, `ConsentPreferencesData`, `UpdateConsentRequest` |
| **Caregiver** | `CaregiverDto.kt` | `InviteCaregiverRequest`, `InvitationCodeData`, `RequestCaregiverAccessRequest`, `CaregiverRelationshipData`, `ApproveCaregiverRequest` |

### Retrofit Interface (`ApiService.kt`)
Defines 20 verified backend routes wrapping responses in `ApiResponse<T>`, including:
- Authentication (`login`, `signup`, `getCurrentUser`, `logout`)
- Care Plan (`getCareTasks`, `getCareTaskDetails`, `toggleCareTask`)
- Appointments (`getAppointments`, `getAppointmentDetails`, `createAppointment`, `updateAppointmentStatus`)
- Wellbeing Journal (`getJournalEntries`, `createJournalEntry`, `getLatestJournalEntry`)
- Consent Governance (`getConsents`, `updateConsent`)
- MoveTrack (`startMoveTrackSession`, `completeMoveTrackSession`, `getMoveTrackSessions`, `getMoveTrackSessionDetails`)
- Caregiver Coordination (`inviteCaregiver`, `requestCaregiverAccess`, `reviewCaregiverRequests`, `approveCaregiverRequest`)

---

## 7. Verification & Test Results

### 7.1 Unit Test Suite (`NetworkFoundationTest.kt`)
A comprehensive test suite was written in `app/src/test/java/com/smamove/android/core/network/NetworkFoundationTest.kt` using `MockWebServer`:

| Test Case | Component Tested | Assertion / Verification | Result |
| :--- | :--- | :--- | :---: |
| `apiConfig_defaultUrl_hasTrailingSlash` | `ApiConfig` | Base URL starts with `http` and ends with `/` | **PASS** |
| `apiConfig_customUrl_normalizedWithTrailingSlash` | `ApiConfig` | Input missing `/` is normalized with `/` | **PASS** |
| `apiConfig_invalidScheme_throwsException` | `ApiConfig` | Rejects non-http schemes (e.g., `ftp://`) | **PASS** |
| `apiConfig_reset_revertsToDefault` | `ApiConfig` | Clears custom URL and restores default | **PASS** |
| `authInterceptor_protectedEndpoint_attachesBearerToken` | `AuthInterceptor` | Adds `Authorization: Bearer <token>` to `/patient/dashboard.php` | **PASS** |
| `authInterceptor_publicLoginEndpoint_excludesBearerToken` | `AuthInterceptor` | Omits `Authorization` header on `/auth/login.php` | **PASS** |
| `authInterceptor_publicSignupEndpoint_excludesBearerToken` | `AuthInterceptor` | Omits `Authorization` header on `/auth/signup.php` | **PASS** |
| `authInterceptor_noTokenStored_sendsNoAuthorizationHeader` | `AuthInterceptor` | Omits header when token storage is empty | **PASS** |
| `authInterceptor_401Unauthorized_clearsTokensAndTriggersCallback` | `AuthInterceptor` | Clears tokens and invokes `onSessionExpired` callback | **PASS** |
| `apiErrorParser_extractsMessage_fromStandardErrorJson` | `ApiErrorParser` | Extracts `message` field from JSON error body | **PASS** |
| `apiErrorParser_extractsValidationErrors` | `ApiErrorParser` | Formats field errors from `errors` map | **PASS** |
| `apiErrorParser_nullOrMalformedJson_fallsBackToDefaultMessage` | `ApiErrorParser` | Gracefully falls back to default HTTP message on invalid JSON | **PASS** |
| `apiErrorParser_mapsThrowables` | `ApiErrorParser` | Maps socket/connection exceptions to friendly copy | **PASS** |
| `retrofit_login_serializesRequestAndDeserializesResponse` | `Retrofit` + Serialization | Verifies JSON payload round-trip on `/auth/login.php` | **PASS** |
| `retrofit_careTasks_deserializesNestedSteps` | `Retrofit` + Serialization | Verifies nested lists and step instructions deserialization | **PASS** |
| `retrofit_moveTrack_startSession` | `Retrofit` + Serialization | Verifies UUID and status serialization | **PASS** |
| `tokenStorage_saveReadAndClear_operatesCorrectly` | `InMemoryTokenStorage` | Verifies save, read, clear tokens, and session attributes | **PASS** |

### 7.2 Unit Test Execution Output
Command executed:
```powershell
.\gradlew.bat testDebugUnitTest
```
Result:
```text
> Task :app:compileDebugKotlin
> Task :app:compileDebugUnitTestKotlin
> Task :app:testDebugUnitTest

BUILD SUCCESSFUL in 1m 11s
26 actionable tasks: 26 executed
```

### 7.3 Debug APK Build Output
Command executed:
```powershell
.\gradlew.bat assembleDebug
```
Result:
```text
> Task :app:compileDebugKotlin UP-TO-DATE
> Task :app:mergeLibDexDebug
> Task :app:packageDebug
> Task :app:assembleDebug

BUILD SUCCESSFUL in 1m 18s
38 actionable tasks: 18 executed, 20 up-to-date
```

---

## 8. Commitments & Constraints Adherence

1. **No UI or Screen Modification:** No Jetpack Compose screens, navigation graphs, or themes were altered during this phase.
2. **Demo Repositories Preserved:** Existing demo repositories (`AuthRepository`, `CaregiverStore`) remain completely untouched and will be systematically migrated in Phase 6.3.
3. **Backend & Database Untouched:** Zero backend PHP files or MariaDB records were altered.
4. **Token Security:** Sensitive credentials and tokens are strictly excluded from logs and protected by Keystore encryption.

---

## 9. Conclusion & Next Step Recommendation

Phase 6.2 has established a secure, thoroughly tested HTTP networking foundation in the SMA-Move Android application.

### Recommended Next Phase:
- **Phase 6.3 — Authentication & Session Integration:**
  - Connect `AuthViewModel` and `AuthSessionStore` to real backend endpoints (`/auth/login.php`, `/auth/signup.php`, `/auth/logout.php`, `/auth/me.php`).
  - Wire `EncryptedTokenStorage` to replace mock in-memory authentication states.
  - Integrate role routing and biometric gate integration.

**STOPPING POINT:** Work on Phase 6.2 is complete. Awaiting user review and authorization before proceeding to Phase 6.3.
