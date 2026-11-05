/**
 * Typed responses.
 *
 * The gateway returns numbers as JSON *strings* - `"credits":"2950"`,
 * `"msgid":"10001"`. That is deliberate and documented, so the models keep
 * them as strings to round-trip faithfully, and expose numeric accessors where
 * a value is genuinely arithmetic. Parsing them into `Number` on the way in
 * would quietly lose precision on long message ids.
 *
 * Each model builds from the raw object, tolerating absent optional fields.
 */

/**
 * The API's `status` flag, named for readability.
 *
 * `"0"` success, `"1"` permanent failure, `"2"`/`"3"` temporary (retryable).
 */
export const StatusValue = Object.freeze({
  SUCCESS: '0',
  PERMANENT: '1',
  TEMPORARY: Object.freeze(['2', '3']),
});

/**
 * Return the first present key. The API is inconsistent about naming: group
 * ids arrive as either `grpid` or `groupid` depending on the route.
 * @param {Record<string, unknown>} raw
 * @param {...string} names
 * @returns {string|null}
 */
function pick(raw, ...names) {
  for (const name of names) {
    const value = raw[name];
    if (value !== undefined && value !== null) return /** @type {any} */ (value);
  }
  return null;
}

/**
 * A JSON scalar as the string the gateway sent, or null when absent.
 * If the gateway ever sends a bare number it is used exactly as written,
 * never through a float round-trip.
 * @param {unknown} value
 * @returns {string|null}
 */
function text(value) {
  if (value === undefined || value === null) return null;
  if (typeof value === 'string') return value;
  if (typeof value === 'number' || typeof value === 'bigint') return String(value);
  return JSON.stringify(value);
}

/**
 * A numeric string as an exact fixed-point decimal, or null when the value is
 * absent or not numeric. Returns a string of the same shape JavaScript's
 * number-to-string uses for finite values (e.g. "1.0"), with leading and
 * trailing zeros dropped.
 * @param {string|null} value
 * @returns {string|null}
 */
function toDecimal(value) {
  if (value === null) return null;
  const match = /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(value.trim());
  if (!match || (match[2] === '' && (match[3] ?? '') === '')) return null;

  const sign = match[1] === '-' ? '-' : '';
  const whole = match[2].replace(/^0+/, '') || '0';
  const fraction = (match[3] ?? '').replace(/0+$/, '');
  return fraction ? `${sign}${whole}.${fraction}` : `${sign}${whole}`;
}

/**
 * Copy the raw response so later mutation by the caller cannot rewrite history.
 * @param {Record<string, unknown>} raw
 * @returns {Record<string, unknown>}
 */
function snapshot(raw) {
  return structuredClone({ ...raw });
}

/**
 * Base model: a `toJSON` that shows the typed fields (not the raw envelope),
 * and an `equals` for tests that avoids field-by-field assertions.
 */
export class Model {
  /** @type {Record<string, unknown>} */
  raw;

  /**
   * @param {Record<string, unknown>} raw
   * @param {Record<string, unknown>} [fields]  Typed fields to expose via toJSON/equals.
   */
  constructor(raw, fields = {}) {
    this.raw = snapshot(raw ?? {});
    /** @protected */
    this._fields = fields;
  }

  /** The typed fields as a plain object. */
  toJSON() {
    return structuredClone(this._fields);
  }

  /**
   * Deep equality against another model of the same class.
   * @param {unknown} other
   * @returns {boolean}
   */
  equals(other) {
    if (other === null || typeof other !== 'object' || other.constructor !== this.constructor) {
      return false;
    }
    return JSON.stringify(this) === JSON.stringify(other);
  }
}

/** Response of `ping()`. */
export class PingResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const ok = raw?.status === StatusValue.SUCCESS;
    super(raw, { ok });
    /** @type {boolean} True when the gateway answered with status '0'. */
    this.ok = ok;
  }
}

/** Response of `getCredits()` and `getSmsCost()`: a credit amount. */
export class Credits extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const credits = text(pick(raw, 'credits'));
    super(raw, { credits });
    /** @type {string|null} Credit amount, e.g. `"2950"` - a string, as the API sends it. */
    this.credits = credits;
  }

  /** @returns {string|null} The amount as an exact decimal string, e.g. `"1"` for `"1.0"`. */
  get asDecimal() {
    return toDecimal(this.credits);
  }
}

/** Response of `getDetails()`. */
export class AccountDetails extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const accountid = text(pick(raw, 'accountid'));
    super(raw, { accountid });
    /** @type {string|null} The account the credential belongs to. */
    this.accountid = accountid;
  }
}

/** One destination's result within a send. */
export class SentMessage extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      msgid: text(pick(raw, 'msgid')),
      dstaddr: text(pick(raw, 'dstaddr')),
      credits: text(pick(raw, 'credits')),
      status: text(pick(raw, 'status')),
      sendtime: text(pick(raw, 'sendtime')),
    };
    super(raw, fields);
    /** @type {string|null} Message id, e.g. `"10001"`. */
    this.msgid = fields.msgid;
    /** @type {string|null} */
    this.dstaddr = fields.dstaddr;
    /** @type {string|null} */
    this.credits = fields.credits;
    /** @type {string|null} Delivery state code. */
    this.status = fields.status;
    /** @type {string|null} `YYYY-MM-DD HH:MM:SS`. */
    this.sendtime = fields.sendtime;
  }
}

/** Response of `sendSms()` and `sendGroupSms()`: a list of {@link SentMessage}. */
export class SendResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const items = list(pick(raw, 'result'));
    const messages = items.map((item) => new SentMessage(item));
    super(raw, { messages });
    /** @type {SentMessage[]} One entry per destination. */
    this.messages = messages;
  }

  /** @returns {string|null} First message id - the common single-recipient case. */
  get msgid() {
    return this.messages.length > 0 ? this.messages[0].msgid : null;
  }
}

/** Response of `querySmsStatus()`. */
export class SmsStatus extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      state: text(pick(raw, 'state')),
      error: text(pick(raw, 'error')),
      finaldate: text(pick(raw, 'finaldate')),
    };
    super(raw, fields);
    /** @type {string|null} Delivery state code. */
    this.state = fields.state;
    /** @type {string|null} Error code reported for the message. */
    this.error = fields.error;
    /** @type {string|null} When delivery ended, or null while in flight. */
    this.finaldate = fields.finaldate;
  }

  /** @returns {boolean} False while the message is still in flight. */
  get final() {
    return Boolean(this.finaldate);
  }
}

/** Response of `lookupHlr()`. */
export class HlrResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      docomo: text(pick(raw, 'docomo')),
      region: text(pick(raw, 'region')),
      info: text(pick(raw, 'info')),
      error: text(pick(raw, 'error')),
    };
    super(raw, fields);
    /** @type {string|null} */
    this.docomo = fields.docomo;
    /** @type {string|null} Region / country. */
    this.region = fields.region;
    /** @type {string|null} Network description. */
    this.info = fields.info;
    /** @type {string|null} */
    this.error = fields.error;
  }
}

/** Response of `lookupMnp()`. */
export class MnpResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      mnpportstatus: text(pick(raw, 'mnpportstatus')),
      mnpportdate: text(pick(raw, 'mnpportdate')),
    };
    super(raw, fields);
    /** @type {string|null} e.g. `"ported"`. */
    this.mnpportstatus = fields.mnpportstatus;
    /** @type {string|null} Porting date. */
    this.mnpportdate = fields.mnpportdate;
  }
}

/** Response of `getNumberInfo()`. */
export class NumberInfo extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      cc: text(pick(raw, 'cc')),
      mcc: text(pick(raw, 'mcc')),
      mnc: text(pick(raw, 'mnc')),
      country: text(pick(raw, 'country')),
      network: text(pick(raw, 'network')),
      credits: text(pick(raw, 'credits')),
    };
    super(raw, fields);
    /** @type {string|null} Calling code. */
    this.cc = fields.cc;
    /** @type {string|null} Mobile country code. */
    this.mcc = fields.mcc;
    /** @type {string|null} Mobile network code. */
    this.mnc = fields.mnc;
    /** @type {string|null} */
    this.country = fields.country;
    /** @type {string|null} */
    this.network = fields.network;
    /** @type {string|null} Credits charged for the lookup. */
    this.credits = fields.credits;
  }
}

/** A group in the account's group list. */
export class Group extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      grpid: text(pick(raw, 'grpid', 'groupid', 'id')),
      name: text(pick(raw, 'name')),
      size: text(pick(raw, 'size', 'entries')),
    };
    super(raw, fields);
    /** @type {string|null} Group id, whether the route sent `grpid` or `groupid`. */
    this.grpid = fields.grpid;
    /** @type {string|null} */
    this.name = fields.name;
    /** @type {string|null} Number of entries. */
    this.size = fields.size;
  }
}

/** Response of `createGroup()`. */
export class CreateGroupResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const grpid = text(pick(raw, 'grpid', 'groupid', 'id'));
    super(raw, { grpid });
    /** @type {string|null} Id of the new group. */
    this.grpid = grpid;
  }
}

/** Response of `getGroupCost()`. */
export class GroupCost extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = {
      credits: text(pick(raw, 'credits')),
      entrycount: text(pick(raw, 'entrycount', 'entries')),
    };
    super(raw, fields);
    /** @type {string|null} Total cost of sending to the group. */
    this.credits = fields.credits;
    /** @type {string|null} Number of destinations. */
    this.entrycount = fields.entrycount;
  }

  /** @returns {string|null} The amount as an exact decimal string. */
  get asDecimal() {
    return toDecimal(this.credits);
  }
}

/** Response of `getGroupNumbers()`. */
export class GroupNumbers extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const numbers = list(pick(raw, 'numbers', 'entry')).map(
      (item) => (typeof item === 'string' ? item : text(pick(item, 'number'))),
    );
    super(raw, { numbers });
    /** @type {(string|null)[]} Phone numbers in the group. */
    this.numbers = numbers;
  }
}

/** Response of `listGroups()`. */
export class GroupList extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const groups = list(pick(raw, 'group')).map((item) => new Group(item));
    super(raw, { groups });
    /** @type {Group[]} Every group on the account. */
    this.groups = groups;
  }
}

/** One number within a group, with its per-number status. */
export class GroupEntry extends Model {
  /**
   * @param {Record<string, unknown>} raw
   * @param {string|null} [number]  Used when the item is a bare number string.
   */
  constructor(raw, number = null) {
    const fields = {
      number: text(pick(raw, 'number')) ?? number,
      status: text(pick(raw, 'status')),
    };
    super(raw, fields);
    /** @type {string|null} */
    this.number = fields.number;
    /** @type {string|null} Addition status code. */
    this.status = fields.status;
  }
}

/** Response of `getGroupDetails()`. */
export class GroupDetails extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const entries = list(pick(raw, 'details', 'entry')).map((item) =>
      (typeof item === 'string' ? new GroupEntry({}, item) : new GroupEntry(item)),
    );
    super(raw, { entries });
    /** @type {GroupEntry[]} */
    this.entries = entries;
  }
}

/** Response of `addGroupEntry()` and `addGroupEntries()`. */
export class AddEntriesResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const fields = { added: text(pick(raw, 'added')), failed: text(pick(raw, 'failed')) };
    super(raw, fields);
    /** @type {string|null} Number of entries added, as a string. */
    this.added = fields.added;
    /** @type {string|null} Number rejected, as a string. */
    this.failed = fields.failed;
  }
}

/** Response of `deleteGroup()`, `deleteGroupEntry()` and `deleteGroupContents()`. */
export class DeleteResult extends Model {
  /** @param {Record<string, unknown>} raw */
  constructor(raw) {
    const deleted = text(pick(raw, 'deleted', 'rows'));
    super(raw, { deleted });
    /** @type {string|null} Rows removed, when the route reports them. */
    this.deleted = deleted;
  }
}

/**
 * Normalise the API's habit of sending a single object where a list is
 * expected (and of omitting the key entirely).
 * @param {unknown} value
 * @returns {Record<string, unknown>[]}
 */
function list(value) {
  if (Array.isArray(value)) return value;
  if (value !== null && typeof value === 'object') return [/** @type {any} */ (value)];
  return [];
}