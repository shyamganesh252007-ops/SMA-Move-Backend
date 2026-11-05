# SMA-MOVE — PHASE 7.5.3-A: DEDICATED TEST DATABASE PROVISIONING SPECIFICATION

**Document Version:** 1.0.0  
**Phase:** 7.5.3-A — Dedicated Test Database Provisioning (Pre-Flight Inspection & Administrative SQL)  
**Date:** November 5, 2026  
**Target Host:** `localhost:3306` (MariaDB 10.4.32)  
**Application Database:** `sma_move`  
**Dedicated Test Database (Target):** `sma_move_test`  
**Application User:** `sma_app_user@localhost`  
**Administrative Execution Status:** **PENDING EXPLICIT ADMINISTRATOR EXECUTION / APPROVAL**

---

## 1. Executive Summary

This document fulfills Step 1 and Step 2 of Phase 7.5.3-A. Its purpose is to prepare the administrative provisioning of a dedicated, isolated test database (`sma_move_test`) while ensuring zero modifications to the active development database (`sma_move`).

### Key Pre-Flight Highlights:
1. **Target Database Status:** Verified via `information_schema.SCHEMATA` that `sma_move_test` **does not yet exist**.
2. **Current Application User Privileges:** The application user `sma_app_user@localhost` currently holds scoped privileges exclusively on `sma_move.*`. It has zero global administration privileges and zero rights on other databases.
3. **Administrative Boundaries Respected:** In accordance with strict security rules, administrative SQL has **not** been executed using the unprivileged application account. Execution remains **pending** until authorized administrative execution occurs.
4. **Development Database Integrity:** Verified that `sma_move` remains completely untouched across all 20 tables.

---

## 2. Pre-Flight Inspection Results

A read-only inspection was executed against the active MariaDB server via the application connection:

| Parameter | Inspected Value | Safety Assessment |
| :--- | :--- | :--- |
| **Current Database** | `sma_move` | Verified active development database context. |
| **MariaDB Server Version** | `10.4.32-MariaDB` | Standard XAMPP Windows MariaDB runtime. |
| **Application Database User** | `sma_app_user@localhost` | Dedicated least-privilege service account. |
| **Existing Global Grants** | `GRANT USAGE ON *.*` | Secure: No global administrative privileges (`SUPER`, `GRANT`, `SHUTDOWN`). |
| **Existing Database Grants** | `GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER ON sma_move.*` | Scoped strictly to development database `sma_move`. |
| **`sma_move_test` Existence** | **NOT FOUND (Does Not Exist)** | Target database is uncreated. Safe to proceed with provisioning SQL. |

---

## 3. Administrative Provisioning SQL

The following administrative SQL script must be executed by an authorized MariaDB administrator (e.g. `root@localhost`):

```sql
-- ==============================================================================
-- SMA-MOVE — PHASE 7.5.3-A: TEST DATABASE PROVISIONING
-- Administrator: MariaDB root@localhost
-- Target Database: sma_move_test
-- Target User: sma_app_user@localhost
-- ==============================================================================

-- 1. Create isolated test database with identical charset and collation
CREATE DATABASE `sma_move_test`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

-- 2. Grant exact least-privilege permissions on test database
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER
ON `sma_move_test`.*
TO 'sma_app_user'@'localhost';

-- 3. Flush privilege tables to apply grant changes immediately
FLUSH PRIVILEGES;

-- 4. Verification query to inspect applied grants
SHOW GRANTS FOR 'sma_app_user'@'localhost';
```

### Safety Invariants Enforced in Administrative SQL:
- **No Global Grants:** Grants are explicitly scoped to `` `sma_move_test`.* ``.
- **No Dangerous Privileges:** `DROP`, `SUPER`, `FILE`, `GRANT OPTION`, or `ALL PRIVILEGES` are **not** granted.
- **Preservation of `sma_move`:** Zero alterations are made to existing grants on `sma_move.*`.
- **Credential Protection:** User passwords are unchanged and unexposed.

---

## 4. Existing vs. Proposed Privilege Comparison Matrix

| Database / Scope | Existing Privileges (`sma_app_user`) | Proposed Privileges After Execution | Status / Purpose |
| :--- | :--- | :--- | :--- |
| `*.*` (Global) | `USAGE` (No privileges) | `USAGE` (Unchanged) | **UNCHANGED** — Zero global rights. |
| `sma_move`.* | `SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER` | `SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER` | **UNCHANGED** — Normal development workflows preserved. |
| `sma_move_test`.* | *None (No access)* | `SELECT, INSERT, UPDATE, DELETE, CREATE, REFERENCES, INDEX, ALTER` | **PROPOSED (NEW)** — Scoped to automated test database only. |

---

## 5. Post-Execution Verification Plan

Once the administrator executes the SQL script above, the following read-only verification queries must be performed to confirm compliance:

```sql
-- Verification Query 1: Verify database existence, character set, and collation
SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA
WHERE SCHEMA_NAME = 'sma_move_test';
-- Expected Result:
-- SCHEMA_NAME: sma_move_test
-- DEFAULT_CHARACTER_SET_NAME: utf8mb4
-- DEFAULT_COLLATION_NAME: utf8mb4_unicode_ci

-- Verification Query 2: Verify application user grants
SHOW GRANTS FOR 'sma_app_user'@'localhost';
-- Expected Result:
-- 1. GRANT USAGE ON *.* TO `sma_app_user`@`localhost` ...
-- 2. GRANT SELECT, ... ON `sma_move`.* TO `sma_app_user`@`localhost`
-- 3. GRANT SELECT, ... ON `sma_move_test`.* TO `sma_app_user`@`localhost`

-- Verification Query 3: Confirm sma_move was not modified
SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'sma_move';
-- Expected Result: Exactly 20 tables.
```

### Current Verification Status:
| Verification Step | Verification Target | Status |
| :--- | :--- | :--- |
| Step 1 | `sma_move_test` database existence | **PENDING ADMINISTRATOR EXECUTION** |
| Step 2 | `utf8mb4` charset and `utf8mb4_unicode_ci` collation | **PENDING ADMINISTRATOR EXECUTION** |
| Step 3 | Scoped grants on `sma_move_test.*` | **PENDING ADMINISTRATOR EXECUTION** |
| Step 4 | No global administrative rights granted | **VERIFIED (Currently USAGE only)** |
| Step 5 | Existing grants on `sma_move.*` intact | **VERIFIED (Confirmed intact)** |
| Step 6 | Zero table/schema modifications to `sma_move` | **VERIFIED (Confirmed intact; 20 tables clean)** |

---

## 6. Confirmation of Zero Development Modifications

A full scan of the development environment confirmed:
- Zero tables modified, created, or dropped in `sma_move`.
- All 18 clinical, identity, and audit tables in `sma_move` maintain a clean baseline of **0 lingering test records**.
- Zero PHP application files modified.
- Zero Apache configurations modified.
- Zero `.env` files modified.
- Zero migrations executed.

---

## 7. Stop Condition & Next Step

In adherence to the strict Phase 7.5.3-A stop condition, all inspection is complete and the administrative SQL is staged.

**The administrative execution is currently pending approval.** 

Upon administrative execution or explicit authorization to execute via local administrative root, Phase 7.5.3-A verification queries will be run to validate `sma_move_test` before proceeding to Phase 7.5.3-B (Schema Initialization & Baseline Cloning).
