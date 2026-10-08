"""Closed signed-package setup policy; no processes or filesystem mutations."""

import hashlib
import re


MODE = "signed-host-php-v1"
FINGERPRINT = "B8DC7E53946656EFBCE4C1DD71DAEAAB4AD4CAB6"
KEY_SHA256 = "7258b1cb18300b87cd5668a6d64ce78184d9c0e129382879a0d79291c4ef463d"
KEYRING_SHA256 = "46865863bccffcf614bf1c8603492c6a5465dcc8d774f0020e7518378ba53466"
KEY_URL = "https://keyserver.ubuntu.com/pks/lookup?op=get&search=0x" + FINGERPRINT
ROOT = "/var/cache/wstm-php-provider"
KEYRING = "/usr/share/keyrings/wstm-ondrej-php-noble.gpg"
SOURCE = "/etc/apt/sources.list.d/wstm-ondrej-php-noble.sources"
DESCRIPTOR = (
    "Types: deb\n"
    "URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu\n"
    "Suites: noble\nComponents: main\nArchitectures: amd64\n"
    "Signed-By: " + KEYRING + " " + FINGERPRINT + "\n"
    "Check-Valid-Until: yes\nValid-Until-Max: 604800\n\n"
    "Types: deb\nURIs: https://archive.ubuntu.com/ubuntu\n"
    "Suites: noble noble-updates\nComponents: main universe\nArchitectures: amd64\n"
    "Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n\n"
    "Types: deb\nURIs: https://security.ubuntu.com/ubuntu\n"
    "Suites: noble-security\nComponents: main universe\nArchitectures: amd64\n"
    "Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n"
).encode("ascii")
DESCRIPTOR_SHA256 = "d3a46bbdbfc615cfdffcdfd36d095b597c89b65bb6019f76065cdaeb56c021db"
JOBS = {"8.2": ("release-package-qa",),
        "8.4": ("ability-contract-qa", "full-mcp-e2e-qa")}
VERSIONS = {"8.2": "8.2.34-1+ubuntu24.04.1+deb.sury.org+1",
            "8.4": "8.4.26-1+ubuntu24.04.1+deb.sury.org+1"}
AUTH = ("-o", "APT::Get::AllowUnauthenticated=false",
        "-o", "Acquire::AllowInsecureRepositories=false",
        "-o", "Acquire::AllowDowngradeToInsecureRepositories=false",
        "-o", "Acquire::Check-Valid-Until=true", "-o", "Acquire::Retries=0")
SOURCES = ("-o", "Dir::Etc::sourcelist=" + SOURCE,
           "-o", "Dir::Etc::sourceparts=-",
           "-o", "Dir::State::lists=" + ROOT + "/lists",
           "-o", "Dir::Cache::archives=" + ROOT + "/archives")
PREFIX = ("/usr/bin/sudo", "-n", "--user=root", "--", "/usr/bin/env", "-i",
          "HOME=/root", "PATH=/usr/bin:/bin", "LC_ALL=C", "DEBIAN_FRONTEND=noninteractive")
OPERATIONS = ("directories", "gnupg-directory", "key-download", "key-dearmor",
              "key-install", "source-descriptor", "apt-update",
              "apt-download", "apt-install", "select-php")
NAME = re.compile(r"[a-z0-9][a-z0-9+.-]{0,127}\Z")
VERSION = re.compile(r"[A-Za-z0-9.+:~_-]{1,128}\Z")
DIGEST = re.compile(r"[a-f0-9]{64}\Z")


class PolicyError(Exception):
    pass


def require(value, reason="unsafe-input"):
    if not value:
        raise PolicyError(reason)


def digest(raw):
    return hashlib.sha256(raw).hexdigest()


def compare_versions(left, right):
    require(type(left) is str and type(right) is str
            and VERSION.fullmatch(left) and VERSION.fullmatch(right), "inventory-shape")

    def parts(value):
        epoch, body = value.split(":", 1) if ":" in value else ("0", value)
        require(epoch.isdigit(), "inventory-shape")
        upstream, revision = body.rsplit("-", 1) if "-" in body else (body, "0")
        require(bool(upstream) and bool(revision), "inventory-shape")
        return int(epoch), upstream, revision

    def order(character):
        if character == "~":
            return -1
        if not character or character.isdigit():
            return 0
        return ord(character) if character.isalpha() else ord(character) + 256

    def component(a, b):
        i = j = 0
        while i < len(a) or j < len(b):
            while ((i < len(a) and not a[i].isdigit())
                   or (j < len(b) and not b[j].isdigit())):
                x, y = a[i] if i < len(a) else "", b[j] if j < len(b) else ""
                if order(x) != order(y):
                    return -1 if order(x) < order(y) else 1
                i += bool(x)
                j += bool(y)
            while i < len(a) and a[i] == "0":
                i += 1
            while j < len(b) and b[j] == "0":
                j += 1
            start_i, start_j = i, j
            while i < len(a) and a[i].isdigit():
                i += 1
            while j < len(b) and b[j].isdigit():
                j += 1
            digits_a, digits_b = a[start_i:i], b[start_j:j]
            if len(digits_a) != len(digits_b):
                return -1 if len(digits_a) < len(digits_b) else 1
            if digits_a != digits_b:
                return -1 if digits_a < digits_b else 1
        return 0

    a, b = parts(left), parts(right)
    if a[0] != b[0]:
        return -1 if a[0] < b[0] else 1
    return component(a[1], b[1]) or component(a[2], b[2])


def entry(version, environment, uid, euid, platform):
    require(platform == "linux" and uid == euid > 0, "platform")
    require(version in JOBS and environment.get("GITHUB_JOB") in JOBS[version], "platform")
    require(environment.get("GITHUB_ACTIONS") == "true"
            and environment.get("RUNNER_ENVIRONMENT") == "github-hosted"
            and environment.get("RUNNER_OS") == "Linux"
            and environment.get("GITHUB_EVENT_NAME") in ("pull_request", "push", "workflow_dispatch")
            and re.fullmatch("[a-f0-9]{40}", environment.get("GITHUB_SHA", "")), "platform")
    require(environment.get("WSTM_HOST_SETUP_RUNNER") == "github-hosted", "platform")
    return version


def lock_tokens(lock, protected_packages):
    require(type(lock) is list and 1 <= len(lock) <= 64, "inventory-shape")
    seen, result = set(), []
    for row in lock:
        require(type(row) is dict and set(row) == {
            "package", "architecture", "version", "sha256", "size",
            "source", "source_version", "repository", "index_sha256",
            "source_index_sha256", "action"}, "inventory-shape")
        require(all(type(row[field]) is str for field in
                    ("package", "architecture", "version", "sha256", "source", "source_version",
                     "repository", "index_sha256", "source_index_sha256", "action")),
                "inventory-shape")
        require(NAME.fullmatch(row["package"]) and VERSION.fullmatch(row["version"])
                and NAME.fullmatch(row["source"]) and VERSION.fullmatch(row["source_version"]),
                "inventory-shape")
        require(row["architecture"] in ("amd64", "all")
                and row["repository"] in ("ppa", "noble", "noble-updates", "noble-security")
                and row["action"] in ("install", "necessary-upgrade"), "inventory-shape")
        require(all(DIGEST.fullmatch(row[field]) for field in
                    ("sha256", "index_sha256", "source_index_sha256")), "inventory-shape")
        require(type(row["size"]) is int and 0 < row["size"] <= 268435456, "file-budget")
        require(row["package"] not in seen and row["package"] not in protected_packages,
                "tool-link")
        seen.add(row["package"])
        architecture = ":amd64" if row["architecture"] == "amd64" else ""
        result.append(row["package"] + architecture + "=" + row["version"])
    require(sum(row["size"] for row in lock) <= 268435456, "file-budget")
    return tuple(result)


def root_argv(operation, version, lock=(), protected_packages=()):
    require(operation in OPERATIONS and version in JOBS)
    require(operation in ("apt-download", "apt-install") or not lock)
    fixed = {
        "directories": ("/usr/bin/install", "-d", "--owner=root", "--group=root",
                        "--mode=0755", "--", ROOT, ROOT + "/lists", ROOT + "/archives"),
        "gnupg-directory": ("/usr/bin/install", "-d", "--owner=root", "--group=root",
                            "--mode=0700", "--", ROOT + "/gnupg"),
        "key-download": ("/usr/bin/curl", "--disable", "--fail", "--silent", "--show-error",
                         "--proto", "=https", "--tlsv1.2", "--max-time", "60",
                         "--max-filesize", "1048576", "--output",
                         ROOT + "/publisher-key.asc", KEY_URL),
        "key-dearmor": ("/usr/bin/gpg", "--no-options", "--homedir", ROOT + "/gnupg",
                        "--batch", "--no-auto-check-trustdb", "--dearmor", "--output",
                        ROOT + "/publisher-key.gpg", ROOT + "/publisher-key.asc"),
        "key-install": ("/usr/bin/install", "--owner=root", "--group=root", "--mode=0644",
                        "--", ROOT + "/publisher-key.gpg", KEYRING),
        "source-descriptor": ("/usr/bin/tee", "--", SOURCE),
        "apt-update": ("/usr/bin/apt-get",) + AUTH + SOURCES + ("update",),
        "select-php": ("/usr/bin/update-alternatives", "--set", "php", "/usr/bin/php" + version),
    }
    if operation in ("apt-download", "apt-install"):
        tokens = lock_tokens(list(lock), protected_packages)
        download = "--download-only" if operation == "apt-download" else "--no-download"
        command = ("/usr/bin/apt-get",) + AUTH + SOURCES + (
            "--yes", download, "--no-install-recommends", "--no-remove", "install") + tokens
    else:
        command = fixed[operation]
    return PREFIX + command


def deb822(raw, limit=64 * 1024 * 1024, *, debconf=False):
    require(type(debconf) is bool, "inventory-shape")
    require(type(raw) is bytes and len(raw) <= limit, "file-budget")
    try:
        text = raw.decode("utf-8", errors="strict")
    except UnicodeError:
        raise PolicyError("inventory-shape") from None
    require("\x00" not in text and "\r" not in text, "inventory-shape")
    records = []
    for paragraph in text.strip().split("\n\n"):
        fields, previous = {}, None
        for line in paragraph.splitlines():
            if line.startswith((" ", "\t")):
                require(previous is not None, "inventory-shape")
                fields[previous] += "\n" + line[1:]
            else:
                require(": " in line or line.endswith(":"), "inventory-shape")
                name, value = line.split(":", 1)
                pattern = "[A-Za-z][A-Za-z0-9_.-]*" if debconf else "[A-Za-z0-9-]+"
                require(re.fullmatch(pattern, name) and name not in fields,
                        "inventory-shape")
                fields[name] = value.strip()
                previous = name
        require(bool(fields), "inventory-shape")
        records.append(fields)
        require(len(records) <= 65536, "file-budget")
    return records


def checksums(value, *, allow_empty=False):
    rows = {}
    for line in value.strip().splitlines():
        fields = line.split()
        require(len(fields) == 3, "inventory-shape")
        sha, size, filename = fields
        require(DIGEST.fullmatch(sha) and size.isdigit()
                and (int(size) > 0 or allow_empty and int(size) == 0 and sha == digest(b""))
                and re.fullmatch("[A-Za-z0-9._+~/-]+", filename)
                and not filename.startswith("/") and ".." not in filename.split("/")
                and filename not in rows, "inventory-shape")
        rows[filename] = {"sha256": sha, "size": int(size)}
    return rows


def signed_prefix(raw):
    require(type(raw) is bytes and len(raw) <= 1048576, "file-budget")
    require(raw.startswith(b"-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA512\n\n"),
            "inventory-shape")
    require(raw.count(b"-----BEGIN PGP SIGNATURE-----") == 1, "inventory-shape")
    return raw.split(b"-----BEGIN PGP SIGNATURE-----", 1)[0]


def release(raw, repository, now):
    prefix = signed_prefix(raw)
    fields = deb822(prefix.split(b"\n\n", 1)[1].rstrip(b"\n"))[0]
    require(repository in ("ppa", "noble", "noble-updates", "noble-security"),
            "inventory-shape")
    require(fields["Suite"] == ("noble" if repository == "ppa" else repository)
            and fields["Codename"] == "noble"
            and "amd64" in fields["Architectures"].split(), "inventory-shape")
    require(fields["Origin"] == ("LP-PPA-ondrej-php" if repository == "ppa" else "Ubuntu"),
            "tool-origin")
    require(fields["Label"] == ("PPA for PHP" if repository == "ppa" else "Ubuntu"),
            "tool-origin")
    from email.utils import parsedate_to_datetime
    stamp = parsedate_to_datetime(fields["Date"]).timestamp()
    require(stamp <= now + 300, "inventory-deadline")
    if repository == "ppa":
        require(now - stamp <= 604800, "inventory-deadline")
    if "Valid-Until" in fields:
        require(now < parsedate_to_datetime(fields["Valid-Until"]).timestamp(),
                "inventory-deadline")
    return fields, checksums(fields["SHA256"], allow_empty=True)


def simulation(raw, wanted, authenticated, installed, protected, necessary=None):
    """Compile only observed mutations whose exact stanzas were authenticated."""
    try:
        text = raw.decode("ascii")
    except UnicodeError:
        raise PolicyError("inventory-shape") from None
    mutations, configured = {}, set()
    for line in text.splitlines():
        require(not line.startswith(("Remv ", "Purg ")), "unsafe-input")
        if line.startswith("Inst "):
            match = re.fullmatch(
                r"Inst ([a-z0-9][a-z0-9+.-]*)(?::amd64)?(?: \[([^]]+)\])? "
                r"\(([A-Za-z0-9.+:~_-]+) .+ \[(amd64|all)\]\)", line)
            require(match is not None and match[1] not in mutations, "inventory-shape")
            name, old, version, architecture = match.groups()
            require(name in authenticated and name not in protected, "tool-link")
            row = authenticated[name]
            require(row["version"] == version and row["architecture"] == architecture
                    and ((old is None and name not in installed)
                         or old == installed.get(name)), "inventory-shape")
            if old is not None:
                require(name in (necessary if necessary is not None else wanted)
                        and compare_versions(version, old) > 0, "unsafe-input")
            mutations[name] = dict(row, action="install" if old is None else "necessary-upgrade")
        elif line.startswith("Conf "):
            match = re.match(r"Conf ([a-z0-9][a-z0-9+.-]*)(?::amd64)? ", line)
            require(match is not None and match[1] not in configured
                    and match[1] in mutations, "inventory-shape")
            configured.add(match[1])
    require(set(mutations) == configured and set(wanted) <= set(mutations) | set(installed),
            "inventory-shape")
    require(all(name in mutations or installed.get(name) == version
                for name, version in wanted.items()), "inventory-shape")
    result = [mutations[name] for name in sorted(mutations)]
    lock_tokens(result, protected)
    return result


def dependency_groups(text):
    require(type(text) is str and len(text) <= 65536, "inventory-shape")
    result = []
    for group in text.split(","):
        if not group.strip():
            continue
        choices = []
        for term in group.split("|"):
            match = re.fullmatch(
                r"\s*([a-z0-9][a-z0-9+.-]*)(?::(?:any|native|amd64))?"
                r"(?:\s+\((<<|<=|=|>=|>>)\s+([A-Za-z0-9.+:~_-]+)\))?\s*", term)
            require(match is not None, "inventory-shape")
            choices.append(match.groups())
        result.append(choices)
    return result


def version_satisfies(version, relation, expected):
    if relation is None:
        return True
    comparison = compare_versions(version, expected)
    return {"<<": comparison < 0, "<=": comparison <= 0, "=": comparison == 0,
            ">=": comparison >= 0, ">>": comparison > 0}[relation]


def necessary_closure(wanted, binary_records, installed):
    """Verify the actual named mutations form a needed dependency closure."""
    related = set(wanted)
    visited = set()
    while related - visited:
        name = sorted(related - visited)[0]
        visited.add(name)
        require(name in binary_records, "inventory-shape")
        row = binary_records[name]
        for field in ("Depends", "Pre-Depends"):
            for alternatives in dependency_groups(row.get(field, "")):
                satisfying = [
                    dependency for dependency, relation, version in alternatives
                    if dependency in installed and dependency not in binary_records
                    and version_satisfies(installed[dependency], relation, version)]
                if satisfying:
                    continue
                transacted = [
                    dependency for dependency, relation, version in alternatives
                    if dependency in binary_records
                    and version_satisfies(binary_records[dependency]["Version"], relation, version)]
                require(len(transacted) == 1, "inventory-shape")
                related.add(transacted[0])
        require(len(related) <= 64, "file-budget")
    require(set(binary_records) == related, "unsafe-input")
    return related


def source_binding(binary, source, binary_index, source_index, repository):
    name = binary["Package"]
    require(NAME.fullmatch(name) and VERSION.fullmatch(binary["Version"])
            and binary["Architecture"] in ("amd64", "all"), "inventory-shape")
    declared = binary.get("Source", name)
    match = re.fullmatch(r"([a-z0-9+.-]+)(?: \(([^)]+)\))?", declared)
    require(match is not None and source["Package"] == match[1]
            and source["Version"] == (match[2] or binary["Version"]), "tool-origin")
    require(bool(checksums(source["Checksums-Sha256"])), "inventory-shape")
    require(re.fullmatch(r"pool/[a-z0-9+./~_-]+\.deb", binary["Filename"])
            and ".." not in binary["Filename"].split("/"), "inventory-shape")
    row = {"package": name, "architecture": binary["Architecture"],
           "version": binary["Version"], "sha256": binary["SHA256"],
           "size": int(binary["Size"]), "source": source["Package"],
           "source_version": source["Version"], "repository": repository,
           "index_sha256": binary_index, "source_index_sha256": source_index,
           "action": "install"}
    lock_tokens([row], ())
    return row


def ucf_binding(version, generated, template, registry, hashes):
    require(version in JOBS)
    target = "/etc/php/" + version + "/cli/php.ini"
    require(type(generated) is bytes and generated == template, "php-config")
    require(any(line.split() == ["php" + version + "-cli", target]
                for line in registry.decode("ascii").splitlines()), "php-config")
    md5 = hashlib.md5(template).hexdigest()
    require(any(line.split() == [md5, target] for line in hashes.decode("ascii").splitlines()),
            "php-config")
    return digest(generated)


def archive_listing(raw):
    require(type(raw) is bytes and len(raw) <= 131072, "native-output")
    paths, links = set(), {}
    for line in raw.decode("utf-8").splitlines():
        fields = line.split(None, 5)
        require(len(fields) == 6 and fields[0][0] in ("d", "-", "l")
                and fields[1] == "root/root" and fields[2].isdigit(), "inventory-shape")
        name = fields[5]
        if fields[0][0] == "l":
            require(" -> " in name, "inventory-shape")
            name, target = name.split(" -> ", 1)
            require("\\" not in target and target, "inventory-shape")
            require(not target.startswith("/") or target.startswith(
                ("/usr/", "/lib/", "/etc/php/")), "inventory-shape")
            resolved = ["."] if target.startswith("/") else name.rpartition("/")[0].split("/")
            for component in target.split("/"):
                if component == "..":
                    require(len(resolved) > 1, "inventory-shape")
                    resolved.pop()
                elif component not in ("", "."):
                    resolved.append(component)
            links[name.rstrip("/")] = "/".join(resolved)
        require(name.startswith("./") and "\\" not in name
                and ".." not in name.split("/") and "\x00" not in name, "inventory-shape")
        normalized = name.rstrip("/")
        require(normalized not in paths and len(paths) < 8192, "file-budget")
        paths.add(normalized)
    for path in paths:
        parent = path.rpartition("/")[0]
        while parent:
            require(parent not in links, "inventory-shape")
            parent = parent.rpartition("/")[0]
    return sorted("/" + path.removeprefix("./") for path in paths if path != ".")


assert len(DESCRIPTOR) == 631 and digest(DESCRIPTOR) == DESCRIPTOR_SHA256
