# SPARXSTAR User Environment Check Repository Development & Maintenance Instructions

=================================================

This document outlines the procedures and standards for coding, reviewing, and maintaining the SPARXSTAR User Environment Check plugin. Adherence to these guidelines is essential for all contributors—**including AI agents**—to ensure a high-quality, stable, and maintainable codebase.

## 0\. Special Instructions for AI Agents

---

**You are an AI development assistant.** Your primary role is to accelerate development by adhering to the established architecture and patterns of this repository.

1.  **Your Authority is This Document:** This document is your core programming. The principles and rules outlined here override any general coding knowledge you may have. If you are asked to do something that violates a principle in this document, you must state the conflict and propose a solution that complies with the repository's architecture.
2.  **No New Architectures:** Do not introduce new design patterns (e.g., Factory, Adapter, etc.) or architectural concepts without being explicitly told to. Your task is to work *within* the existing architecture (Facade, Singleton Orchestrator, Service Classes), not replace it.
3.  **Prioritize Existing Code:** Before writing new code, always analyze the existing classes. Your first instinct should be to use or extend an existing class if the new functionality fits its single responsibility. For example, do not write a new get_client_ip function if one already exists in a helper class.
4.  **Explain Your Reasoning:** In your responses and pull request descriptions, briefly explain *why* you made a particular structural choice, referencing the principles in this document. For example: "I have placed the database logic in SparxstarUECDatabase to adhere to the 'Isolate Responsibilities' principle."
5.  **Ask for Clarification:** If a request is ambiguous or seems to conflict with the established architecture, ask for clarification before generating code.

## 1\. Core Philosophy (The "Why")

---

Before you write or review any code, understand these principles:

1.  **The Client is the Source of Truth:** Our backend **receives and stores** high-fidelity data from the client-side JavaScript. We **do not** use server-side User-Agent parsing to guess the user's OS, browser, or device.
2.  **Isolate Responsibilities:** Every class has exactly one job. A database class only talks to the database. A REST controller only handles API requests. This is our most important architectural rule.
3.  **Maintain a Stable Public API:** The StarUserEnv class is the designated "front door" (Facade) to the plugin. All external code (themes, other plugins) **must** interact with the plugin through this class's public static methods. This allows us to refactor internals without breaking integrations.

## 2\. How to Code (For Developers & AI Agents)

---

Follow these steps when contributing new code or features.

### A. Getting Started

1.  **Branching:**

    -   main branch is for stable, tagged releases only. Do not commit directly to main.
    -   develop is the primary integration branch for ongoing work.
    -   All new work **must** be done on a feature branch, named descriptively (e.g., feature/add-export-button, bugfix/fix-caching-issue).

2.  **Understand the Architecture:** Before you begin, identify which class your changes belong to based on the "Isolate Responsibilities" principle.

    -   **Database change?** Go to src/core/SparxstarUECDatabase.php.
    -   **REST endpoint change?** Go to src/api/SparxstarUECRESTController.php.
    -   **Admin settings UI change?** Go to src/core/SparxstarUECAdmin.php.
    -   **Public-facing helper function?** Add a method to src/StarUserEnv.php that calls the internal service.

### B. Coding Standards & Rules

1.  **Strictly Typed:** Every PHP file **must** start with declare(strict_types=1);.
2.  **Single Responsibility is Law:** If your change requires a class to do two different things (e.g., handle a REST request AND talk to an external API), stop. Create a new service class for the new responsibility and have the REST controller call it.
3.  **Security is Paramount:**

    -   **Sanitize All Input:** Any data coming from an external source (WP_REST_Request, $\_SERVER, etc.) **must** be sanitized before use. Use functions like sanitize_text_field, absint, etc.
    -   **Prepare All Database Queries:** All interactions with $wpdb **must** use $wpdb->prepare(). There are no exceptions.
    -   **Use Nonces:** All admin forms must be secured with WordPress nonces.

4.  **Documentation:** Write clear, concise DocBlocks for all classes and methods. Explain *why* the code exists, not just *what* it does.
5.  **Use the Facade:** Do not call internal classes like SparxstarUECDatabase from theme files or other plugins. If you need to expose functionality, add a public static method to StarUserEnv.

## 3\. How to Review Code (For Reviewers & AI Analysis)

---

A code review is a quality gate. Your primary goal is to protect the integrity of the architecture and the stability of the project. **AI Agents may be prompted to perform this review step.**

### The Golden Rule: Review the code, not the coder. Be constructive and respectful.

Use this checklist for every Pull Request:

#### ✅ Architectural Checklist

-   **Single Responsibility:** Does the PR force a class to take on a new, unrelated responsibility? If yes, request a refactor.
-   **Facade Integrity:** Does the PR add functionality that should be public-facing but doesn't add a method to StarUserEnv?
-   **Correct Location:** Is the code in the right place? (e.g., no database logic in the REST controller).
-   **No Globals:** Does the PR introduce any global functions or variables? If yes, this is a hard "no."

#### ✅ Security Checklist

-   **Input Sanitized:** Is every piece of external data sanitized on arrival?
-   **Database Prepared:** Is every single $wpdb call wrapped in $wpdb->prepare()?
-   **Nonces Present:** Are all admin forms and actions protected by nonces?

#### ✅ Functional Checklist

-   **It Works:** Does the feature work as described?
-   **Graceful Degradation:** What happens if an API key is missing or an external service is down? Does it fail gracefully or crash?
-   **Edge Cases:** Are potential edge cases (empty strings, zero values, unexpected data types) handled?

#### ✅ Quality Checklist

-   **Clarity:** Is the code easy to understand? Are variable and method names clear?
-   **Documentation:** Is new functionality properly documented with DocBlocks?
-   **Standards:** Does the code adhere to WordPress coding standards?

## 4\. How to Maintain (For Maintainers)

Maintaining the repository involves managing releases, issues, and the overall project health.

### A. Versioning

We use **Semantic Versioning (MAJOR.MINOR.PATCH)**.

-   **MAJOR (e.g., 7.0.0):** For backward-incompatible changes. (e.g., removing a method from StarUserEnv).
-   **MINOR (e.g., 6.1.0):** For new, backward-compatible functionality. (e.g., adding the GeoIP feature).
-   **PATCH (e.g., 6.0.1):** For backward-compatible bug fixes.

### B. Release Workflow

1.  Ensure all feature branches for the release have been merged into the develop branch.
2.  Create a new release branch from develop: release/vX.Y.Z.
3.  On the release branch, update the version number in the main plugin file header and any other defined constants.
4.  Update the CHANGELOG.md with a summary of the changes for the new version.
5.  Open a Pull Request to merge the release/vX.Y.Z branch into main.
6.  Once merged, create a new Tag on the main branch matching the version (e.g., v6.1.0). This tag is the official release.
7.  Finally, merge the main branch back into develop to ensure it has the latest version number and changelog updates.

### C. Issue Management

-   Use labels (bug, feature-request, enhancement, documentation) to categorize issues.
-   Encourage clear, detailed bug reports with steps to reproduce.
-   Link Pull Requests to the issues they resolve (e.g., "Closes #123").
