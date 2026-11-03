package net.worldtext.api;


/** One number in a group, with its per-number status. */
public final class GroupEntry {

    private final String number;
    private final String status;

    public GroupEntry(String number, String status) {
        this.number = number;
        this.status = status;
    }

    /** The number, e.g. {@code "447989000001"}. */
    public String getNumber() {
        return number;
    }

    /** Per-number status code as sent, e.g. {@code "5"}, or {@code null}. */
    public String getStatus() {
        return status;
    }

    @Override
    public boolean equals(Object other) {
        if (this == other) {
            return true;
        }
        if (!(other instanceof GroupEntry)) {
            return false;
        }
        GroupEntry that = (GroupEntry) other;
        return eq(number, that.number) && eq(status, that.status);
    }

    @Override
    public int hashCode() {
        return (number == null ? 0 : number.hashCode()) * 31 + (status == null ? 0 : status.hashCode());
    }

    @Override
    public String toString() {
        return "GroupEntry[number=" + number + ", status=" + status + "]";
    }

    private static boolean eq(String a, String b) {
        return a == null ? b == null : a.equals(b);
    }
}