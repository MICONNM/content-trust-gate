# Content Trust Gate

**WordPress Plugin – Mandatory AI Content Risk Control**

---

## Overview

**Content Trust Gate** is a WordPress plugin designed to pre-block high-risk content across posts, comments, API submissions, automation pipelines, feeds, and ads.  
It enforces a mandatory content verification system with logging and immutable decision tracking, preventing bypass and reducing content liability risks.

**Key Features:**
- BLOCK / HOLD / PASS judgment for posts and comments
- Automation pipeline control
- Feed and API content gate
- Ad content evaluation
- Logging of all decisions with timestamps
- Immutable decision storage
- No external service required – fully self-contained

---

## Demo Examples

### Posts

| Title | Content | Result |
|-------|---------|--------|
| "100% Guaranteed Success" | "Follow this plan and you will succeed." | BLOCK |
| "Daily Tips for Better Sleep" | "Here are some research-backed methods." | PASS |

### Comments

| Author | Comment | Result |
|--------|---------|--------|
| John | "This is absolutely the best method ever!" | BLOCK |
| Alice | "I like this article." | PASS |

### Automation & API

- Automation pipelines receive `BLOCK` or `HOLD` responses if content violates rules.
- API calls return structured status with risk level and responsibility shift.

---

## Installation

1. Upload the `content-trust-gate` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Create posts or comments to see BLOCK / HOLD / PASS in action
4. Logs are automatically stored in `wp_ctg_logs` and `wp_ctg_decisions`

---

## Benefits for Enterprises

- Reduce legal and misinformation liability
- Ensure content quality and duplication control
- Protect API endpoints, automation, feeds, and ads
- Fully auditable decision history
- Free and open-source (GPLv2 or later)
- Easy integration with existing WordPress workflows

---

## Contribution

- Fork the repository
- Test in your environment
- Submit Pull Requests for improvements

---

## License

GPLv2 or later – free for commercial and personal use

---

## Assets

Include `assets/screenshots/` folder for visual guidance:
- Installation screenshot
- BLOCK/HOLD/PASS example
- Demo post and comment screenshots