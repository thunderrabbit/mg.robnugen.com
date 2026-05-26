# Decision Matrix API — Implementation Spec

## Goal

Add API endpoints and Jikan MCP tools so Claude agents can read from and write to the Decision Matrix. All data must remain encrypted at rest using the same client-side encryption scheme the browser UI uses.

## Context

The Decision Matrix is a belief transformation tool with three data types:
- **Problem**: A limiting belief (e.g. "I'm not good enough")
- **Opposite**: The empowering flip (e.g. "I am capable and worthy")
- **Evidence**: Proof the opposite is true (many per opposite)

Everything is encrypted client-side before storage. The server never sees plaintext.

## Current Architecture

### Database Tables (schema: `db_schemas/19_decision_matrix/`)

```sql
dm_my_problems (dm_mp PK, user_id, problem BLOB, created_at, updated_at)
dm_my_opposites (dm_mo PK, dm_mp FK UNIQUE, user_id, opposite BLOB, created_at, updated_at)
dm_my_evidence (dm_me PK, dm_mo FK, user_id, evidence BLOB, created_at, updated_at)
dm_user_config (user_id PK, passphrase_verify BLOB)
```

Note: Primary keys are unique across the project (not just the table). `dm_mp`, `dm_mo`, `dm_me` — never generic `id`.

### Encryption (file: `wwwroot/js/dm_crypto.js`)

- **Key derivation**: PBKDF2, SHA-256, 600,000 iterations
- **Cipher**: AES-256-GCM
- **Blob format**: Base64( salt[16 bytes] + iv[12 bytes] + ciphertext )
- **Verification**: Encrypt "DECISION_MATRIX_OK" with passphrase, store in `dm_user_config.passphrase_verify`. To verify, decrypt and check string matches.

The passphrase is user-chosen, cached in `sessionStorage` as `dm_pass`, and NEVER sent to the server.

### Existing Browser UI (files: `wwwroot/matrix/*.php`, templates: `templates/matrix/*.tpl.php`)

- `setup.php` — Create passphrase (stores verification blob)
- `add_problem.php` — POST encrypted_problem
- `add_opposite.php?dm_mp={id}` — POST encrypted_opposite
- `add_evidence.php?dm_mo={id}` — POST encrypted_evidence
- `index.php` — View all (loads encrypted blobs, decrypts client-side)

### Existing API Pattern (file: `wwwroot/api/v1/index.php`)

All API requests use `X-API-Key` header for auth. The router in `index.php` dispatches to handler files like `_sessions.php`, `_emotions.php`, `_todos.php`, `_inbox.php`. Follow this pattern.

## What To Build

### 1. PHP API Endpoints (new file: `wwwroot/api/v1/_matrix.php`)

Follow the pattern in `_sessions.php` and `_emotions.php`. Use `$auth_user_id` from the router.

#### GET /api/v1/matrix
Returns the full encrypted matrix for the authenticated user.

Response:
```json
{
  "problems": [
    {
      "dm_mp": 1,
      "problem": "<base64 encrypted blob>",
      "opposite": {
        "dm_mo": 1,
        "opposite": "<base64 encrypted blob>",
        "evidence": [
          { "dm_me": 1, "evidence": "<base64 encrypted blob>" },
          { "dm_me": 2, "evidence": "<base64 encrypted blob>" }
        ]
      }
    }
  ],
  "passphrase_verify": "<base64 encrypted blob>"
}
```

#### POST /api/v1/matrix/problem
Add a limiting belief.

Request: `{ "encrypted_problem": "<base64 blob>" }`
Response: `{ "dm_mp": <new_id> }`

#### POST /api/v1/matrix/opposite
Add the empowering opposite for a problem.

Request: `{ "dm_mp": <problem_id>, "encrypted_opposite": "<base64 blob>" }`
Response: `{ "dm_mo": <new_id> }`

#### POST /api/v1/matrix/evidence
Add evidence for an opposite.

Request: `{ "dm_mo": <opposite_id>, "encrypted_evidence": "<base64 blob>" }`
Response: `{ "dm_me": <new_id> }`

#### POST /api/v1/matrix/setup
Initialize passphrase verification (only if `dm_user_config` row doesn't exist for user).

Request: `{ "passphrase_verify": "<base64 blob>" }`
Response: `{ "success": true }`

### 2. Route Registration (file: `wwwroot/api/v1/index.php`)

Add routing for `/matrix` and `/matrix/*` paths, dispatching to `_matrix.php`. Follow the existing pattern for how `/sessions`, `/emotions`, etc. are routed.

### 3. Python Encryption Module (new file: `~/jikan/dm_crypto.py`)

Port the JavaScript encryption from `wwwroot/js/dm_crypto.js` to Python. Must produce identical output format (Base64 of salt + iv + ciphertext) so the browser UI can decrypt agent-created entries and vice versa.

```python
import os, base64, hashlib
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC
from cryptography.hazmat.primitives import hashes

ITERATIONS = 600_000
SALT_LEN = 16
IV_LEN = 12

def derive_key(passphrase: str, salt: bytes) -> bytes:
    kdf = PBKDF2HMAC(algorithm=hashes.SHA256(), length=32, salt=salt, iterations=ITERATIONS)
    return kdf.derive(passphrase.encode('utf-8'))

def encrypt(passphrase: str, plaintext: str) -> str:
    salt = os.urandom(SALT_LEN)
    iv = os.urandom(IV_LEN)
    key = derive_key(passphrase, salt)
    aesgcm = AESGCM(key)
    ciphertext = aesgcm.encrypt(iv, plaintext.encode('utf-8'), None)
    return base64.b64encode(salt + iv + ciphertext).decode('ascii')

def decrypt(passphrase: str, blob_b64: str) -> str:
    raw = base64.b64decode(blob_b64)
    salt = raw[:SALT_LEN]
    iv = raw[SALT_LEN:SALT_LEN + IV_LEN]
    ciphertext = raw[SALT_LEN + IV_LEN:]
    key = derive_key(passphrase, salt)
    aesgcm = AESGCM(key)
    return aesgcm.decrypt(iv, ciphertext, None).decode('utf-8')

def verify(passphrase: str, verify_blob_b64: str) -> bool:
    try:
        return decrypt(passphrase, verify_blob_b64) == "DECISION_MATRIX_OK"
    except Exception:
        return False
```

**IMPORTANT**: Before building MCP tools, write a test that encrypts with Python and decrypts with the JS `dm_crypto.js` (and vice versa) to confirm compatibility. Read `dm_crypto.js` carefully — check if the `encrypt` function uses any associated data (AAD) parameter in GCM, and match it exactly.

### 4. Jikan MCP Tools (file: `~/jikan/server.py`)

Add a new env var: `MATRIX_PASSPHRASE` — the user's Decision Matrix passphrase.

Tools to add:

```python
@mcp.tool()
def list_matrix() -> dict:
    """Retrieve and decrypt the full Decision Matrix."""
    # GET /api/v1/matrix
    # Decrypt all blobs using MATRIX_PASSPHRASE
    # Return plaintext structure

@mcp.tool()
def add_matrix_problem(problem: str) -> dict:
    """Add a limiting belief to the Decision Matrix."""
    # Encrypt problem text
    # POST /api/v1/matrix/problem

@mcp.tool()
def add_matrix_opposite(dm_mp: int, opposite: str) -> dict:
    """Add the empowering opposite for a limiting belief."""
    # Encrypt opposite text
    # POST /api/v1/matrix/opposite

@mcp.tool()
def add_matrix_evidence(dm_mo: int, evidence: str) -> dict:
    """Add evidence that the empowering belief is true."""
    # Encrypt evidence text
    # POST /api/v1/matrix/evidence
```

The MCP tools should encrypt before sending and decrypt after receiving, so the agent works with plaintext and the server only ever sees encrypted blobs.

### 5. Environment Configuration

Add to the Jikan MCP server environment (wherever JIKAN_API_KEY is configured):

```
MATRIX_PASSPHRASE=<user's matrix passphrase>
```

**Security note**: This means any agent with access to Jikan can read/write the matrix. That's the tradeoff for agent access. The passphrase is stored in the agent's env, not in the database.

## Testing Checklist

- [ ] Python encrypt → JS decrypt works
- [ ] JS encrypt → Python decrypt works
- [ ] API endpoints respect `user_id` isolation
- [ ] API returns proper errors for missing fields, unauthorized access
- [ ] MCP tools can list, add problems, add opposites, add evidence
- [ ] Browser UI still works with agent-created entries
- [ ] Passphrase verification works via API

## Files to Read Before Starting

1. `wwwroot/js/dm_crypto.js` — The encryption implementation (source of truth)
2. `wwwroot/api/v1/index.php` — API router pattern
3. `wwwroot/api/v1/_emotions.php` — Example API handler with encryption (uses `\Emotional\Ledger`)
4. `wwwroot/matrix/index.php` — How the browser UI loads and structures data
5. `wwwroot/matrix/add_problem.php` — How problems are inserted
6. `db_schemas/19_decision_matrix/create_decision_matrix.sql` — Table definitions
7. `~/jikan/server.py` — Existing MCP tools pattern

## Deploy

After implementation:
1. Commit PHP changes in `~/work/rob/mg.robnugen.com/`
2. Deploy: `bash deploy_on_dh.sh`
3. Commit Jikan changes in `~/jikan/`
4. Restart Jikan MCP server
5. Test from Claude Code: `list_matrix()` should return decrypted entries
