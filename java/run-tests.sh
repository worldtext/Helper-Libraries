#!/bin/sh
# Build the World Text Java client and run its test suite.
#
#   ./run-tests.sh                      # everything
#   ./run-tests.sh Json                 # only tests whose name contains "Json"
#
# Exits 0 when every test passes (integration tests skipped for lack of
# python3 do not fail the run), 1 when a test or the build fails.
set -eu

HERE=$(cd "$(dirname "$0")" && pwd)
cd "$HERE"

BUILD=build
CLASSES="$BUILD/classes"
TEST_CLASSES="$BUILD/test-classes"
JAVAC_OPTS="-encoding UTF-8 -Xlint:all -Werror"
JAVA_OPTS="-Dfile.encoding=UTF-8"

command -v javac >/dev/null 2>&1 || {
    echo "javac not found: a JDK 8 or newer is required" >&2
    exit 1
}

rm -rf "$BUILD"
mkdir -p "$CLASSES" "$TEST_CLASSES"

echo "compiling library..."
find src/main/java -name '*.java' > "$BUILD/sources.txt"
javac $JAVAC_OPTS -d "$CLASSES" @"$BUILD/sources.txt"

echo "compiling tests..."
find src/test/java -name '*.java' > "$BUILD/test-sources.txt"
javac $JAVAC_OPTS -cp "$CLASSES" -d "$TEST_CLASSES" @"$BUILD/test-sources.txt"

echo "running tests..."
exec java $JAVA_OPTS -cp "$CLASSES:$TEST_CLASSES" net.worldtext.api.TestRunner "$@"