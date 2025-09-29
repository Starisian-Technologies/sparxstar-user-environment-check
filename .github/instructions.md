# AI Coding Context Instructions

## 1\. Project Overview

---

This is a WordPress plugin designed to collect high-fidelity user environment and browser diagnostic data.

-   **Frontend:** Client-side JavaScript libraries (device-detector.js, Network Information API, etc.) collect precise data about the user's environment.
-   **Backend:** A WordPress plugin receives this data via a REST API endpoint, sanitizes it, enriches it with GeoIP information (if configured), and stores it in a custom database table.
-   **Core Goal:** To provide accurate, client-first diagnostic data, avoiding unreliable server-side guessing (e.g., User-Agent parsing for OS/browser).

This document outlines the core philosophy and development principles for this repository. All contributions, whether from human or AI agents, must strictly adhere to these guidelines to maintain the project's long-term health, stability, and maintainability.

## 1\. Project Philosophy: The Unchanging Vision

---

The fundamental goal of this plugin is to accurately capture and store high-fidelity, client-side diagnostic data. This mission statement informs all architectural decisions.

1.  **The Client is the Authoritative Source of Truth:** The server's primary role is to **receive, trust, and store** the detailed data sent by client-side libraries. The backend **must not** engage in inaccurate, server-side guessing of environmental details like OS, browser, or device type by parsing User-Agent strings. The client's data is considered superior.
2.  **Accuracy Over Convenience:** We prefer precise data from the client over easily-obtained but unreliable data from the server.
3.  **Robustness and Graceful Degradation:** The plugin must not cause fatal errors. Features depending on external services (like GeoIP) or optional components (like Action Scheduler) must function correctly or silently disable themselves if the dependency is not met.

## 2\. Core Architectural Principles

---

These are the non-negotiable rules of the codebase. They must be respected in all refactoring and feature development.

### Principle I: Isolate Responsibilities

Every class must have one, and only one, distinct responsibility. We strictly avoid "God Classes" that do multiple unrelated things. When adding a new feature, always ask: "Which single responsibility does this belong to?" If one does not exist, create a new class.

Logic must be separated into the following domains:

-   **Database Logic:** Any code that directly interacts with the database ($wpdb) must be isolated in a dedicated database class. No other class should ever contain SQL queries.
-   **REST API Logic:** Any code that registers REST routes, handles WP_REST_Request objects, or returns WP_REST_Response objects must be in a dedicated REST controller class.
-   **Caching Logic:** Any code that interacts with the WordPress Object Cache (wp_cache\_\* functions) must be in a dedicated cache helper class.
-   **External Service Logic:** Any code that makes outbound API calls to a third-party service (e.g., a GeoIP provider) must be in its own dedicated service class.
-   **Admin UI Logic:** Any code that creates admin menus, settings pages, or renders HTML in the WordPress admin area must be in a dedicated admin class.

### Principle II: Maintain a Clean Public API Facade

There must be a single, designated class (currently StarUserEnv) that serves as the stable, public-facing API for the entire plugin. Any external code (themes, other plugins) that needs to interact with this plugin's functionality should **only** call the public static methods of this Facade class. This protects the internal architecture from being tightly coupled to external code, allowing for safe internal refactoring.

### Principle III: The Main Plugin File is a Lean Bootstrapper

The root plugin file (.php) must remain as simple as possible. Its **only** responsibilities are:

1.  Defining global constants.
2.  Including the necessary class files (require_once).
3.  Registering activation and deactivation hooks.
4.  Making a single call to initialize the main plugin orchestrator.

**Do not add business logic, WordPress hooks, or functions to this file.**

## 3\. Code Conventions

---

1.  **Strict Typing:** All PHP files must begin with declare(strict_types=1);.
2.  **Naming:**

    -   Class names should share a consistent, project-specific prefix.
    -   Directory names within the src folder must be lowercase.

3.  **Dependency Management:** Favor Dependency Injection. Classes should receive their dependencies (like a database object) through their constructor rather than creating them internally. This makes classes decoupled and easier to test.
4.  **Security is Non-Negotiable:**

    -   All database queries must be prepared.
    -   All data from external sources (REST API payloads, $\_SERVER variables) must be sanitized and validated before use.
    -   All WordPress admin forms must be protected by nonces.

## 4\. Common Development Patterns

---

### How to Add a New Feature

1.  **Identify the Responsibility:** Determine which architectural domain the new feature belongs to (Database, REST API, External Service, etc.).
2.  **Create or Locate the Class:** If a class for that responsibility already exists, add the new logic there. If not, create a new, dedicated class that follows the Single Responsibility Principle.
3.  **Integrate:** Wire the new class or functionality into the main plugin orchestrator.
4.  **Expose (If Necessary):** If the new feature needs to be accessible by external code, add a simple, clean public method to the StarUserEnv Facade class that delegates the call to the new internal service.

## 5\. Prohibited Patterns (Anti-patterns)

---

To protect the architecture, the following patterns are strictly forbidden:

-   **Global Functions:** Do not add any new functions to the global namespace. All functionality must be encapsulated within classes.
-   **Direct Superglobal Access:** Do not use $\_POST, $\_GET, or $\_SERVER outside of a dedicated Request or REST Controller class. Data should be sanitized there and then passed as arguments to other parts of the system.
-   **Mixed Responsibilities:** Do not add database queries to a REST controller or REST API logic to the main orchestrator. **Always respect the separation of concerns.**
-   **Misplaced Hooks:** Do not place add_action or add_filter calls in the main plugin file or in the global scope. They belong inside a class, typically registered in the constructor or a dedicated register_hooks method.
