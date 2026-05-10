-- ============================================================
-- CognitiveAI - Database Setup Script (Fresh Database)
-- SQL Server Management Studio (SSMS)
--
-- HOW TO RUN:
--   1. Make sure you have already created the Cognitive database:
--         CREATE DATABASE Cognitive;
--   2. Open a New Query window in SSMS
--   3. Paste this entire file and press F5
--   4. Check the Messages tab for [OK] confirmations
-- ============================================================

USE [Cognitive];
GO

-- ============================================================
-- TABLE 1: ACCOUNTS
-- Stores all users: admins, practitioners, and patients.
-- ============================================================
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'ACCOUNTS'
)
BEGIN
    CREATE TABLE ACCOUNTS (
        ACCOUNT_ID   INT           IDENTITY(1,1) PRIMARY KEY,
        USERNAME     VARCHAR(100)  NOT NULL,
        EMAIL        VARCHAR(200)  NOT NULL UNIQUE,
        PASSWORD     VARCHAR(500)  NOT NULL,
        ROLE         VARCHAR(20)   NOT NULL DEFAULT 'practitioner',
            -- 'admin' | 'practitioner' | 'patient'
        DATE_CREATED DATETIME      NOT NULL DEFAULT GETDATE(),
        SUBSCRIPTION VARCHAR(20)   NOT NULL DEFAULT 'free'
            -- 'free' | 'basic' | 'premium' | 'none'
    );
    PRINT '[OK] Created ACCOUNTS table.';
END
ELSE
    PRINT '[SKIP] ACCOUNTS table already exists.';
GO

-- ============================================================
-- TABLE 2: SUBSCRIPTIONS
-- Tracks each practitioner's active plan.
-- ============================================================
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'SUBSCRIPTIONS'
)
BEGIN
    CREATE TABLE SUBSCRIPTIONS (
        SUBSCRIPTION_ID  INT          IDENTITY(1,1) PRIMARY KEY,
        ACCOUNT_ID       INT          NOT NULL,
        PLAN_TYPE        VARCHAR(20)  NOT NULL DEFAULT 'basic',
            -- 'basic' = 10 assessments/month | 'premium' = unlimited
        START_DATE       DATETIME     NOT NULL DEFAULT GETDATE(),
        END_DATE         DATETIME     NOT NULL,
        STATUS           VARCHAR(20)  NOT NULL DEFAULT 'active',
            -- 'active' | 'expired' | 'cancelled'
        MONTHLY_LIMIT    INT          NULL,
            -- 10 for basic, NULL for premium (unlimited)
        CONSTRAINT FK_SUBS_ACCOUNT
            FOREIGN KEY (ACCOUNT_ID) REFERENCES ACCOUNTS(ACCOUNT_ID)
            ON DELETE CASCADE
    );
    PRINT '[OK] Created SUBSCRIPTIONS table.';
END
ELSE
    PRINT '[SKIP] SUBSCRIPTIONS table already exists.';
GO

-- ============================================================
-- TABLE 3: PATIENTS
-- Each row is a patient linked to the practitioner who created them.
-- ============================================================
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'PATIENTS'
)
BEGIN
    CREATE TABLE PATIENTS (
        PATIENT_ID       INT           IDENTITY(1,1) PRIMARY KEY,
        ACCOUNT_ID       INT           NOT NULL,  -- patient's own login account
        PRACTITIONER_ID  INT           NOT NULL,  -- practitioner who created them
        FULL_NAME        VARCHAR(100)  NOT NULL,
        DATE_ADDED       DATETIME      NOT NULL DEFAULT GETDATE(),
        CONSTRAINT FK_PATIENTS_ACCOUNT
            FOREIGN KEY (ACCOUNT_ID) REFERENCES ACCOUNTS(ACCOUNT_ID),
        CONSTRAINT FK_PATIENTS_PRACTITIONER
            FOREIGN KEY (PRACTITIONER_ID) REFERENCES ACCOUNTS(ACCOUNT_ID)
    );
    PRINT '[OK] Created PATIENTS table.';
END
ELSE
    PRINT '[SKIP] PATIENTS table already exists.';
GO

-- ============================================================
-- TABLE 4: ASSESSMENTS
-- Stores every cognitive test result.
-- ============================================================
IF NOT EXISTS (
    SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_NAME = 'ASSESSMENTS'
)
BEGIN
    CREATE TABLE ASSESSMENTS (
        ASSESSMENT_ID       INT           IDENTITY(1,1) PRIMARY KEY,
        PATIENT_ID          INT           NOT NULL,
        PRACTITIONER_ID     INT           NOT NULL,
        DATE_ASSESSED       DATETIME      NOT NULL DEFAULT GETDATE(),
        -- Form inputs
        AGE                 INT,
        GENDER              VARCHAR(10),
        SLEEP_DURATION      FLOAT,
        STRESS_LEVEL        INT,
        DIET_TYPE           VARCHAR(30),
        DAILY_SCREEN_TIME   FLOAT,
        EXERCISE_FREQUENCY  VARCHAR(20),
        CAFFEINE_INTAKE     FLOAT,
        REACTION_TIME       FLOAT,
        MEMORY_TEST_SCORE   FLOAT,
        -- AI output
        COGNITIVE_SCORE     FLOAT,
        NOTES               VARCHAR(MAX),
        CONSTRAINT FK_ASSESS_PATIENT
            FOREIGN KEY (PATIENT_ID) REFERENCES PATIENTS(PATIENT_ID)
            ON DELETE CASCADE,
        CONSTRAINT FK_ASSESS_PRACTITIONER
            FOREIGN KEY (PRACTITIONER_ID) REFERENCES ACCOUNTS(ACCOUNT_ID)
    );
    PRINT '[OK] Created ASSESSMENTS table.';
END
ELSE
    PRINT '[SKIP] ASSESSMENTS table already exists.';
GO

-- ============================================================
-- SEED: Admin account
--
-- Login with:
--   Email:    admin@cognitiveai.local
--   Password: Admin@1234
--
-- Change this password after your first login!
-- ============================================================
IF NOT EXISTS (SELECT 1 FROM ACCOUNTS WHERE ROLE = 'admin')
BEGIN
    INSERT INTO ACCOUNTS (USERNAME, EMAIL, PASSWORD, ROLE, DATE_CREATED, SUBSCRIPTION)
    VALUES (
        'admin',
        'admin@cognitiveai.local',
        '$2y$10$TKh8H1.PfQ0A32L2tl/3.OBnLDKhS1bL7LMBiXe3eS.7PXJzL8Pq6',
        'admin',
        GETDATE(),
        'none'
    );
    PRINT '[OK] Admin account seeded — Email: admin@cognitiveai.local / Pass: Admin@1234';
END
ELSE
    PRINT '[SKIP] Admin account already exists.';
GO

-- ============================================================
-- VERIFY: Show all tables and their column counts
-- ============================================================
SELECT
    t.TABLE_NAME,
    (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS c
     WHERE c.TABLE_NAME = t.TABLE_NAME) AS COLUMN_COUNT
FROM INFORMATION_SCHEMA.TABLES t
WHERE t.TABLE_TYPE = 'BASE TABLE'
  AND t.TABLE_NAME IN ('ACCOUNTS','SUBSCRIPTIONS','PATIENTS','ASSESSMENTS')
ORDER BY t.TABLE_NAME;
GO

PRINT '============================================================';
PRINT ' Setup complete!';
PRINT ' Expected results:';
PRINT '   ACCOUNTS      = 7 columns';
PRINT '   SUBSCRIPTIONS = 7 columns';
PRINT '   PATIENTS      = 5 columns';
PRINT '   ASSESSMENTS   = 16 columns';
PRINT '============================================================';
GO
