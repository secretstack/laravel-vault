# Define a SecretProvider contract, ship only the Vault driver

**Context:** The package is required to be "extensible for future secret providers," but there is exactly one provider (HashiCorp Vault) and no concrete second-provider roadmap (IBID is an Azure shop; Azure Key Vault is at most aspirational). Designing a multi-provider abstraction from a sample size of one reliably produces a leaky interface that the second provider then has to break.

**Decision:** Define a minimal contract and ship a single implementation. No provider machinery.

```php
interface SecretProvider {
    /** @return array<string,string> */
    public function fetch(): array; // throws SecretProviderException
}
```

- Ship exactly one implementation: `VaultSecretProvider`.
- Bind behind a single config key (`secrets.provider`, default `vault`); swapping is a binding change, not a framework.
- **Do not build** provider auto-discovery, a manager with `extend()`, multi-provider merge, or per-key routing. Those are designed *with* the second real provider, not speculatively.
- All Vault-isms (lease, KV-v2 version, AppRole) stay **inside** `VaultSecretProvider`. The contract returns a flat `array<string,string>` and knows nothing about Vault.

**Why:** A thin interface gives Liskov-clean extensibility and test seams for free; the machinery is YAGNI until a second provider actually exists.
