/**
 * World Text API client - JavaScript/Node.
 *
 * Zero dependencies. Node 20+ (developed on Node 26); ES modules only.
 *
 * @example
 * import { WorldText, WorldTextError } from 'world-text';
 *
 * const wt = new WorldText({ token: 'wt_live_...' });
 * console.log((await wt.getCredits()).credits);   // '2950', as a string, like the API
 * await wt.sendSms('447989000001', 'Hello');
 *
 * Numbers in the API are JSON strings; the models keep them as strings and
 * offer an `asDecimal` getter where a value is genuinely numeric.
 *
 * @module world-text
 */

export { WorldText, redact, DEFAULT_BASE_URL, DEFAULT_TIMEOUT } from './lib/client.js';
export {
  WorldTextError,
  AuthenticationError,
  PermissionError,
  ApiError,
  TransportError,
} from './lib/errors.js';
export {
  StatusValue,
  Model,
  PingResult,
  Credits,
  AccountDetails,
  SentMessage,
  SendResult,
  SmsStatus,
  HlrResult,
  MnpResult,
  NumberInfo,
  Group,
  GroupList,
  GroupCost,
  GroupNumbers,
  GroupEntry,
  GroupDetails,
  AddEntriesResult,
  CreateGroupResult,
  DeleteResult,
} from './lib/models.js';

/** Client version. */
export const VERSION = '1.0.0';