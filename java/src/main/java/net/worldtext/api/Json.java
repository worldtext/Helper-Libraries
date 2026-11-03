package net.worldtext.api;

import java.math.BigDecimal;
import java.util.ArrayList;
import java.util.Collections;
import java.util.LinkedHashMap;
import java.util.List;
import java.util.Map;

/**
 * A small, strict JSON reader for the World Text API.
 *
 * <p>The library targets Java 8 with no external dependencies, so this replaces
 * a JSON library. It is deliberately narrow: it parses JSON text into
 * {@link Map}, {@link List}, {@link String}, {@link BigDecimal}, {@link Boolean}
 * and {@code null}, and it is defensive about everything else.
 *
 * <p>Design choices that matter to callers of this API:
 * <ul>
 *   <li>Numbers become {@link BigDecimal}, never {@code double}. The gateway
 *       stringifies numbers, but if one ever arrives bare it must not silently
 *       lose precision.</li>
 *   <li>Objects are {@link LinkedHashMap}s, so field order is preserved when
 *       debugging.</li>
 *   <li>Parsing is strict: trailing garbage, unescaped control characters in
 *       strings, malformed escapes and illegal {@code \\u} sequences are
 *       rejected with {@link ParseException} rather than guessed at.</li>
 *   <li>Escapes are fully decoded, so message text containing quotes,
 *       backslashes, newlines or non-ASCII characters round-trips exactly.</li>
 * </ul>
 *
 * <p>Instances are immutable and safe to share between threads.
 */
final class Json {

    private Json() {
        // Static helpers only.
    }

    /** Thrown when the input is not valid JSON. */
    static final class ParseException extends Exception {
        private static final long serialVersionUID = 1L;

        ParseException(String message, int offset) {
            super(message + " at offset " + offset);
        }
    }

    /**
     * Parse a complete JSON document.
     *
     * @param text JSON text; must not be {@code null}
     * @return a Map, List, String, BigDecimal, Boolean or null
     * @throws ParseException if the text is not valid JSON
     */
    static Object parse(String text) throws ParseException {
        if (text == null) {
            throw new ParseException("no input", 0);
        }
        Parser parser = new Parser(text);
        parser.skipWhitespace();
        if (parser.atEnd()) {
            throw new ParseException("empty input", parser.pos);
        }
        Object value = parser.readValue();
        parser.skipWhitespace();
        if (!parser.atEnd()) {
            throw new ParseException("trailing data", parser.pos);
        }
        return value;
    }

    /**
     * Parse a JSON document that must be an object - the everyday case for
     * this API, whose every response is an envelope.
     *
     * @throws ParseException if the text is not a JSON object
     */
    @SuppressWarnings("unchecked")
    static Map<String, Object> parseObject(String text) throws ParseException {
        Object value = parse(text);
        if (!(value instanceof Map)) {
            throw new ParseException("expected a JSON object", 0);
        }
        return (Map<String, Object>) value;
    }

    // ------------------------------------------------------------------ parser

    private static final class Parser {
        private final String src;
        private int pos;

        Parser(String src) {
            this.src = src;
        }

        boolean atEnd() {
            return pos >= src.length();
        }

        private char peek() throws ParseException {
            if (pos >= src.length()) {
                throw new ParseException("unexpected end of input", pos);
            }
            return src.charAt(pos);
        }

        void skipWhitespace() {
            while (pos < src.length()) {
                char c = src.charAt(pos);
                if (c == ' ' || c == '\t' || c == '\n' || c == '\r') {
                    pos++;
                } else {
                    break;
                }
            }
        }

        Object readValue() throws ParseException {
            char c = peek();
            switch (c) {
                case '{':
                    return readObject();
                case '[':
                    return readArray();
                case '"':
                    return readString();
                case 't':
                    expectWord("true");
                    return Boolean.TRUE;
                case 'f':
                    expectWord("false");
                    return Boolean.FALSE;
                case 'n':
                    expectWord("null");
                    return null;
                default:
                    if (c == '-' || (c >= '0' && c <= '9')) {
                        return readNumber();
                    }
                    throw new ParseException("unexpected character '" + c + "'", pos);
            }
        }

        private void expectWord(String word) throws ParseException {
            if (!src.startsWith(word, pos)) {
                throw new ParseException("expected '" + word + "'", pos);
            }
            pos += word.length();
        }

        private Map<String, Object> readObject() throws ParseException {
            Map<String, Object> object = new LinkedHashMap<String, Object>();
            pos++; // '{'
            skipWhitespace();
            if (peek() == '}') {
                pos++;
                return object;
            }
            while (true) {
                skipWhitespace();
                if (peek() != '"') {
                    throw new ParseException("expected an object key", pos);
                }
                String key = readString();
                skipWhitespace();
                if (peek() != ':') {
                    throw new ParseException("expected ':' after key \"" + key + "\"", pos);
                }
                pos++;
                skipWhitespace();
                object.put(key, readValue());
                skipWhitespace();
                char c = peek();
                if (c == ',') {
                    pos++;
                } else if (c == '}') {
                    pos++;
                    return object;
                } else {
                    throw new ParseException("expected ',' or '}' in object", pos);
                }
            }
        }

        private List<Object> readArray() throws ParseException {
            List<Object> array = new ArrayList<Object>();
            pos++; // '['
            skipWhitespace();
            if (peek() == ']') {
                pos++;
                return array;
            }
            while (true) {
                skipWhitespace();
                array.add(readValue());
                skipWhitespace();
                char c = peek();
                if (c == ',') {
                    pos++;
                } else if (c == ']') {
                    pos++;
                    return array;
                } else {
                    throw new ParseException("expected ',' or ']' in array", pos);
                }
            }
        }

        private String readString() throws ParseException {
            pos++; // opening quote
            StringBuilder out = new StringBuilder();
            while (true) {
                char c = peek();
                if (c == '"') {
                    pos++;
                    return out.toString();
                }
                if (c == '\\') {
                    pos++;
                    out.append(readEscape());
                } else if (c < 0x20) {
                    // Raw control characters are not legal JSON; a real newline
                    // inside a string almost always means broken escaping.
                    throw new ParseException(
                            String.format("unescaped control character U+%04X in string", (int) c), pos);
                } else {
                    out.append(c);
                    pos++;
                }
            }
        }

        private char readEscape() throws ParseException {
            char c = peek();
            pos++;
            switch (c) {
                case '"':
                    return '"';
                case '\\':
                    return '\\';
                case '/':
                    return '/';
                case 'b':
                    return '\b';
                case 'f':
                    return '\f';
                case 'n':
                    return '\n';
                case 'r':
                    return '\r';
                case 't':
                    return '\t';
                case 'u':
                    return readUnicodeEscape();
                default:
                    throw new ParseException("invalid escape '\\" + c + "'", pos - 1);
            }
        }

        private char readUnicodeEscape() throws ParseException {
            if (pos + 4 > src.length()) {
                throw new ParseException("truncated \\u escape", pos);
            }
            int value = 0;
            for (int i = 0; i < 4; i++) {
                int digit = Character.digit(src.charAt(pos + i), 16);
                if (digit < 0) {
                    throw new ParseException("invalid hex digit in \\u escape", pos + i);
                }
                value = (value << 4) | digit;
            }
            pos += 4;
            // Surrogate halves are returned as-is; the StringBuilder reassembles
            // them into a valid pair for any well-formed input.
            return (char) value;
        }

        private BigDecimal readNumber() throws ParseException {
            int start = pos;
            if (peek() == '-') {
                pos++;
            }
            // Integer part: '0' or a digit run not starting with '0'.
            char c = peek();
            if (c == '0') {
                pos++;
            } else if (c >= '1' && c <= '9') {
                while (pos < src.length() && isDigit(src.charAt(pos))) {
                    pos++;
                }
            } else {
                throw new ParseException("invalid number", start);
            }
            if (pos < src.length() && src.charAt(pos) == '.') {
                pos++;
                if (pos >= src.length() || !isDigit(src.charAt(pos))) {
                    throw new ParseException("invalid number fraction", start);
                }
                while (pos < src.length() && isDigit(src.charAt(pos))) {
                    pos++;
                }
            }
            if (pos < src.length() && (src.charAt(pos) == 'e' || src.charAt(pos) == 'E')) {
                pos++;
                if (pos < src.length() && (src.charAt(pos) == '+' || src.charAt(pos) == '-')) {
                    pos++;
                }
                if (pos >= src.length() || !isDigit(src.charAt(pos))) {
                    throw new ParseException("invalid number exponent", start);
                }
                while (pos < src.length() && isDigit(src.charAt(pos))) {
                    pos++;
                }
            }
            try {
                return new BigDecimal(src.substring(start, pos));
            } catch (NumberFormatException e) {
                throw new ParseException("invalid number", start);
            }
        }

        private static boolean isDigit(char c) {
            return c >= '0' && c <= '9';
        }
    }

    // ------------------------------------------------------------ type helpers

    /** Return {@code object[key]} as a String, or {@code null} if absent or not a string. */
    static String string(Map<String, Object> object, String key) {
        Object value = object == null ? null : object.get(key);
        return value instanceof String ? (String) value : null;
    }

    /** Return {@code object[key]} as a List, or an empty list if absent or not an array. */
    @SuppressWarnings("unchecked")
    static List<Object> list(Map<String, Object> object, String key) {
        Object value = object == null ? null : object.get(key);
        return value instanceof List ? (List<Object>) value : Collections.<Object>emptyList();
    }

    /** Return {@code object[key]} as a Map, or {@code null} if absent or not an object. */
    @SuppressWarnings("unchecked")
    static Map<String, Object> map(Map<String, Object> object, String key) {
        Object value = object == null ? null : object.get(key);
        return value instanceof Map ? (Map<String, Object>) value : null;
    }
}