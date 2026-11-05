package net.worldtext.api;

import java.util.Map;

/** The account the credential belongs to. Requires {@code http}. */
public final class AccountDetails extends Model {

    private final String accountid;

    public AccountDetails(Map<String, Object> raw) {
        super(raw);
        this.accountid = firstOf(getRaw(), "accountid");
    }

    public String getDetailsname() {
        return accountid;
    }
}