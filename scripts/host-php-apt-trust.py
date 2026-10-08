"""Finite installed SYSTEM policy closure; no process launch or privilege."""

import ast
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import struct


class TrustError(Exception):
    pass


def require(value, reason="tool-loader"):
    if not value:
        raise TrustError(reason)


DPKG_PATH = "/usr/sbin:/usr/bin:/sbin:/bin"
HOOKS = {
    "/usr/sbin/dpkg-preconfigure --apt || true": {
        "key": "DPkg::Pre-Install-Pkgs::", "package": "debconf",
        "helpers": ("/usr/sbin/dpkg-preconfigure", "/usr/bin/perl"),
        "effects": ("debconf-package-preconfiguration",)},
    "if /usr/bin/test -w /var/lib/command-not-found/ -a -e /usr/lib/cnf-update-db; "
    "then /usr/lib/cnf-update-db > /dev/null; fi": {
        "key": "APT::Update::Post-Invoke-Success::", "package": "command-not-found",
        "helpers": ("/usr/bin/test", "/usr/lib/cnf-update-db", "/usr/bin/python3"),
        "effects": ("command-not-found-cache",)},
    "if /usr/bin/test -e /usr/share/dbus-1/system-services/org.freedesktop.PackageKit.service "
    "&& /usr/bin/test -S /var/run/dbus/system_bus_socket; then /usr/bin/gdbus call --system "
    "--dest org.freedesktop.PackageKit --object-path /org/freedesktop/PackageKit "
    "--method org.freedesktop.PackageKit.StateHasChanged cache-update > /dev/null; fi": {
        "key": "APT::Update::Post-Invoke-Success::", "package": "packagekit",
        "helpers": ("/usr/bin/test", "/usr/bin/gdbus"),
        "effects": ("packagekit-cache-update-notification",)},
    "if test -w /var/cache/app-info -a -e /usr/bin/appstreamcli; then appstreamcli "
    "refresh-cache > /dev/null; fi": {
        "key": "APT::Update::Post-Invoke-Success::", "package": "appstream",
        "helpers": ("/usr/bin/test", "/usr/bin/appstreamcli"),
        "effects": ("appstream-cache",)},
    "if /usr/bin/test -w /var/cache/swcatalog -a -e /usr/bin/appstreamcli; then "
    "/usr/bin/appstreamcli refresh-cache > /dev/null; fi": {
        "key": "APT::Update::Post-Invoke-Success::", "package": "appstream",
        "helpers": ("/usr/bin/test", "/usr/bin/appstreamcli"),
        "effects": ("appstream-cache",)},
}
INSTALL_HELPERS = {
    "/usr/bin/dpkg": "dpkg", "/usr/bin/dpkg-trigger": "dpkg",
    "/usr/bin/dpkg-maintscript-helper": "dpkg", "/usr/bin/update-alternatives": "dpkg",
    "/usr/sbin/start-stop-daemon": "dpkg", "/usr/sbin/ldconfig": "libc-bin",
    "/usr/bin/ucf": "ucf", "/usr/bin/ucfr": "ucf",
    "/usr/sbin/phpquery": "php-common", "/usr/sbin/phpenmod": "php-common",
    "/usr/sbin/phpdismod": "php-common",
    "/usr/lib/php/php-maintscript-helper": "php-common",
    "/usr/lib/php/sessionclean": "php-common",
    "/usr/bin/deb-systemd-helper": "init-system-helpers",
    "/usr/bin/deb-systemd-invoke": "init-system-helpers",
    "/usr/sbin/invoke-rc.d": "init-system-helpers",
    "/usr/sbin/update-rc.d": "init-system-helpers",
}
TRIGGER_HELPERS = {
    "libc-bin": ("/usr/sbin/ldconfig",),
    "man-db": ("/usr/bin/mandb",),
    "systemd": ("/usr/bin/systemctl",),
    "ca-certificates": ("/usr/sbin/update-ca-certificates", "/usr/bin/openssl"),
    "php-common": ("/usr/sbin/phpquery", "/usr/sbin/phpenmod", "/usr/sbin/phpdismod"),
    "ucf": ("/usr/bin/ucf", "/usr/bin/ucfr"),
    "debconf": ("/usr/sbin/dpkg-preconfigure",),
    "shared-mime-info": ("/usr/bin/update-mime-database",),
}
SCRIPT_INTERPRETERS = {
    "/bin/sh": "/usr/bin/dash", "/usr/bin/perl": "/usr/bin/perl",
    "/usr/bin/python3": "/usr/bin/python3", "/bin/bash": "/usr/bin/bash",
    "/usr/bin/bash": "/usr/bin/bash"}
SUPPORT_CONFIG = frozenset(("/etc/ucf.conf", "/etc/default/locale"))
SUPPORT_FILES = frozenset((
    "/usr/share/debconf/confmodule", "/usr/lib/php/php-maintscript-helper",
    "/usr/bin/gettext.sh", "/usr/share/ucf/ucf_helper_functions.sh"))
UTILITIES = frozenset((
    "basename", "cat", "chmod", "chown", "cmp", "cp", "cut", "date", "dd", "diff",
    "dirname", "echo", "expr", "find", "getent", "grep", "head", "id", "install",
    "ln", "ls", "md5sum", "mkdir", "mktemp", "mv", "od", "readlink", "rm", "sed",
    "sha256sum", "sleep", "sort", "stat", "tail", "tar", "test", "touch", "tr",
    "uname", "uniq", "wc", "which", "xargs"))
BUILTIN_PYTHON = frozenset((
    "sys", "builtins", "posix", "_io", "_thread", "_stat", "time", "errno",
    "gc", "marshal", "_imp", "itertools", "_sre", "_warnings", "_weakref",
    "_signal", "_operator", "_collections", "_functools", "_abc", "_ast",
    "_codecs", "_string", "_locale", "atexit", "pwd", "grp"))
WINDOWS_ONLY = frozenset(("msvcrt", "_winapi", "winreg", "nt"))


def python_support(provider, raw, seen, package_name=""):
    tree = ast.parse(raw)
    imports = []
    for node in ast.walk(tree):
        if isinstance(node, ast.Import):
            imports.extend(alias.name for alias in node.names)
        elif isinstance(node, ast.ImportFrom):
            if node.level:
                prefix = package_name.split(".")
                require(node.level <= len(prefix) + 1)
                module = ".".join(prefix[:len(prefix) + 1 - node.level])
                if node.module:
                    module = ".".join(filter(None, (module, node.module)))
            else:
                module = node.module or ""
            if module:
                imports.append(module)
                for alias in node.names:
                    if alias.name != "*":
                        child = module + "." + alias.name
                        relative = child.replace(".", "/")
                        if any((root / (relative + ".py")).is_file()
                               or (root / relative / "__init__.py").is_file()
                               for root in (Path("/usr/lib/python3/dist-packages"),)
                               + tuple(Path("/usr/lib").glob("python3.*"))):
                            imports.append(child)
        elif isinstance(node, ast.Call):
            function = node.func
            dynamic = ((isinstance(function, ast.Name) and function.id == "__import__")
                       or (isinstance(function, ast.Attribute)
                           and function.attr == "import_module"))
            if dynamic:
                require(node.args and isinstance(node.args[0], ast.Constant)
                        and type(node.args[0].value) is str, "tool-loader")
                imports.append(node.args[0].value)
    roots = [Path("/usr/lib/python3/dist-packages")]
    roots += [path for path in sorted(Path("/usr/lib").glob("python3.*"))
              if path.is_dir() and re.fullmatch(r"python3\.[0-9]+", path.name)]
    roots += [path / "lib-dynload" for path in list(roots)]
    for module in sorted(set(imports)):
        first = module.split(".")[0]
        if first in BUILTIN_PYTHON or first in WINDOWS_ONLY:
            continue
        require(re.fullmatch(r"[A-Za-z_][A-Za-z0-9_.]*", module))
        relative = module.replace(".", "/")
        candidates = set()
        for root in roots:
            for path in (root / (relative + ".py"), root / relative / "__init__.py"):
                if path.is_file():
                    candidates.add(os.path.realpath(path))
            for path in root.glob(relative + ".*.so"):
                candidates.add(os.path.realpath(path))
        require(len(candidates) == 1, "tool-origin")
        path = candidates.pop()
        if path in seen:
            continue
        seen.add(path)
        require(len(seen) <= 256, "file-budget")
        row = provider.bound_file(path)
        provider.dependencies(row["canonical"])
        if row["raw"].startswith(b"\x7fELF"):
            continue
        python_support(provider, row["raw"], seen,
                       module if path.endswith("/__init__.py") else module.rpartition(".")[0])


def perl_support(provider, raw, seen):
    text = raw.decode("utf-8")
    require(not re.search(r"\brequire\s+[$@]", text), "tool-loader")
    modules = sorted(set(re.findall(
        r"\b(?:use|require)\s+([A-Za-z_][A-Za-z0-9_]*(?:::[A-Za-z_][A-Za-z0-9_]*)*)",
        text)))
    roots = [Path("/usr/share/perl5")]
    roots += [path for path in sorted(Path("/usr/share/perl").glob("*")) if path.is_dir()]
    roots += [path for path in sorted(Path("/usr/lib/x86_64-linux-gnu/perl").glob("*"))
              if path.is_dir()]
    for module in modules:
        relative = module.replace("::", "/") + ".pm"
        candidates = {os.path.realpath(root / relative) for root in roots
                      if (root / relative).is_file()}
        require(len(candidates) == 1, "tool-origin")
        path = candidates.pop()
        if path in seen:
            continue
        seen.add(path)
        require(len(seen) <= 256, "file-budget")
        row = provider.bound_file(path)
        perl_support(provider, row["raw"], seen)


def apt_dump(raw):
    require(type(raw) is bytes and len(raw) <= 131072, "native-output")
    try:
        text = raw.decode("ascii")
    except UnicodeError:
        raise TrustError("inventory-shape") from None
    result = []
    for line in text.splitlines():
        match = re.fullmatch(r'([A-Za-z0-9_:/+.-]+)\s+("(?:[^"\\]|\\["\\])*");', line)
        require(match is not None, "inventory-shape")
        value = json.loads(match[2])
        require("\x00" not in value and "\n" not in value and "\r" not in value,
                "inventory-shape")
        result.append((match[1], value))
    return result


def verify_environment(rows):
    values = {}
    for name, value in rows:
        if name.startswith("Dir::Bin::"):
            require(name in ("Dir::Bin::dpkg", "Dir::Bin::methods", "Dir::Bin::solvers",
                             "Dir::Bin::planners")
                    and value == {
                        "Dir::Bin::dpkg": "/usr/bin/dpkg",
                        "Dir::Bin::methods": "/usr/lib/apt/methods",
                        "Dir::Bin::solvers": "/usr/lib/apt/solvers",
                        "Dir::Bin::planners": "/usr/lib/apt/planners"}[name])
        if name.startswith(("DPkg::Options::", "Acquire::https::CaInfo",
                            "Acquire::https::SslCert", "Acquire::https::SslKey",
                            "Acquire::https::Verify-", "APT::Update::Error-Mode")):
            require(value == "")
        if not name.endswith("::"):
            require(name not in values, "inventory-shape")
            values[name] = value
    require(values.get("DPkg::Path") == DPKG_PATH)
    for name, expected in (
        ("Dir::Bin::dpkg", "/usr/bin/dpkg"),
        ("DPkg::Run-Directory", "/"),
        ("APT::Sandbox::User", "_apt")):
        require(name not in values or values[name] == expected)
    for name, value in rows:
        if (name.startswith(("DPkg::Chroot", "DPkg::Root", "APT::Solver", "APT::Planner",
                             "Acquire::http::Proxy", "Acquire::https::Proxy"))
                or name == "Dir::Etc::netrc"):
            require(value in ("", "/etc/apt/auth.conf") if name == "Dir::Etc::netrc" else value == "")
        if name in ("APT::Get::AllowUnauthenticated", "Acquire::AllowInsecureRepositories",
                    "Acquire::AllowDowngradeToInsecureRepositories"):
            require(value == "false")
    return values


def shell_literal(raw):
    """Decode quoting and escapes without evaluating shell expansions."""
    result, quote, offset = [], None, 0
    while offset < len(raw):
        char = raw[offset]
        if quote == "'":
            if char == "'":
                quote = None
            else:
                result.append(char)
            offset += 1
            continue
        if char == "\\":
            require(offset + 1 < len(raw), "tool-loader")
            following = raw[offset + 1]
            if quote == '"' and following not in '$`"\\\n':
                result.append(char)
                offset += 1
                continue
            if following != "\n":
                result.append(following)
            offset += 2
            continue
        if char == quote:
            quote = None
        elif quote is None and char in ("'", '"'):
            quote = char
        else:
            result.append(char)
        offset += 1
    require(quote is None, "tool-loader")
    return "".join(result)


def shell_continuations(text):
    """Remove shell line continuations before identifying word boundaries."""
    result, quote, offset = [], None, 0
    word_start = True
    pending = []
    while offset < len(text):
        char = text[offset]
        if quote is None and char == "\n" and pending:
            result.append(char)
            offset += 1
            for delimiter, tabs, literal in pending:
                while True:
                    end = text.find("\n", offset)
                    require(end != -1, "tool-loader")
                    line = text[offset:end]
                    offset = end + 1
                    if (line.lstrip("\t") if tabs else line) == delimiter:
                        break
                    require(literal or not any(value in line for value in ("$", "`", "\\")),
                            "tool-loader")
                result.append("\n")
            pending = []
            word_start = True
            continue
        if quote is None and word_start and char == "#":
            end = text.find("\n", offset)
            offset = len(text) if end == -1 else end
            continue
        if quote is None and char == "<":
            following = offset + 1
            while text.startswith("\\\n", following):
                following += 2
            if following < len(text) and text[following] == "<":
                header = re.match(
                    r"""<(-?)[ \t]*(?:([A-Za-z0-9_]+)|'([A-Za-z0-9_]+)'|"([A-Za-z0-9_]+)")(?=[ \t\r\n;&|)]|$)""",
                    text[following:])
                require(header is not None, "tool-loader")
                delimiter = next(value for value in header.groups()[1:] if value is not None)
                pending.append((delimiter, header[1] == "-", header[2] is None))
                result.append(" " * (following + header.end() - offset))
                offset = following + header.end()
                word_start = True
                continue
        if char == "\\" and quote != "'" and offset + 1 < len(text):
            following = text[offset + 1]
            if following != "\n":
                result.extend((char, following))
                word_start = False
            offset += 2
            continue
        if char == quote:
            quote = None
        elif quote is None and char in ("'", '"'):
            quote = char
        word_start = quote is None and char in " \t\r\n;&|()<>"
        result.append(char)
        offset += 1
    require(not pending, "tool-loader")
    return "".join(result)


def script_sources(text):
    text = shell_continuations(text)
    word = r"""(?:[^ \t\r\n;&|()<>'"\\]|\\[\s\S]|'[^']*'|"(?:[^"\\]|\\[\s\S])*")+"""
    token = re.compile(word)
    boundary = r"(?=[ \t<>]|$)[ \t]*"
    redirection = r"[0-9]*(?:<<-|<<<|<<|>>|<>|>&|<&|>\||>|<)[ \t]*" + word + boundary
    prefix = re.compile(
        r"(?:[A-Za-z_][A-Za-z0-9_]*=(?:" + word + r")?" + boundary + "|" + redirection + ")")
    redirect = re.compile(redirection)
    positions = re.finditer(
        r"(?m)(?:^[ \t]*|(?:[;&|(){}!`]|"
        r"\b(?:if|elif|while|until|then|do|else)\b)[ \t]*)", text)
    sources, covered_until = [], 0

    def after_space(offset):
        while offset < len(text) and text[offset] in " \t":
            offset += 1
        return offset

    for position in positions:
        if position.start() < covered_until:
            continue
        offset = position.end()
        while True:
            leading = prefix.match(text, offset)
            if leading:
                offset = leading.end()
                continue
            command = token.match(text, offset)
            if command is None:
                break
            name = shell_literal(command[0])
            offset = after_space(command.end())
            if name in ("command", "builtin"):
                option = token.match(text, offset)
                while option and shell_literal(option[0]) in ("-p", "--"):
                    offset = after_space(option.end())
                    option = token.match(text, offset)
                continue
            if name in (".", "source"):
                leading = redirect.match(text, offset)
                while leading:
                    offset = leading.end()
                    leading = redirect.match(text, offset)
                operand = token.match(text, offset)
                require(operand is not None, "tool-loader")
                sources.append(shell_literal(operand[0]))
                covered_until = operand.end()
            break
    return sources


def helper(provider, path, expected_package=None, seen=None):
    seen = set() if seen is None else seen
    if path in seen:
        return
    seen.add(path)
    require(len(seen) <= 256, "file-budget")
    transaction = (hasattr(type(provider), "transaction_active")
                   and provider.transaction_active())
    rows = (provider.transaction_helper_rows(path, expected_package) if transaction else [
        provider.bound_file(path, expected_package) if os.path.lexists(path)
        else provider.future_helper(path, expected_package)])
    for row in rows:
        helper_source(provider, path, row, seen, transaction)


def helper_source(provider, path, row, seen, transaction):
    if transaction:
        require(hasattr(type(provider), "transaction_active")
                and provider.transaction_active(), "tool-origin")
        require(any(candidate["canonical"] == row["canonical"]
                    and candidate["sha256"] == row["sha256"]
                    and candidate["raw"] == row["raw"]
                    and candidate["package"] == row["package"]
                    for candidate in provider.transaction_helper_rows(path)), "tool-origin")
    canonical = row["canonical"]
    if row.get("future", False):
        require(transaction or not row["raw"].startswith(b"\x7fELF"), "tool-loader")
    else:
        provider.dependencies(canonical)
    raw = row["raw"]
    if path == "/usr/lib/php/php-helper":
        require(row["package"][0].split(":")[0] == "php-common"
                and hashlib.sha256(raw).hexdigest()
                == "72445eb0e4d94093f9f5b78038370b01741222cb7498f15ccb5cf8d476dbd83a",
                "tool-loader")
    if raw.startswith(b"\x7fELF"):
        return
    first = raw.splitlines()[0].decode("ascii").removeprefix("#!").strip().split()
    if raw.startswith(b"#!"):
        require(first and first[0] in SCRIPT_INTERPRETERS
                and all(flag in ("-w", "-T", "-S")
                        or (first[0] == "/bin/sh" and flag == "-e") for flag in first[1:]))
        helper(provider, SCRIPT_INTERPRETERS[first[0]], seen=seen)
        if first[0] == "/usr/bin/python3":
            python_support(provider, raw, seen)
        elif first[0] == "/usr/bin/perl":
            perl_support(provider, raw, seen)
    else:
        require(path in SUPPORT_FILES, "tool-elf")
    # Default installed scripts may load only protected installed support data.
    # The package manifest supplies the complete source bytes, not user cfg.
    text = raw.decode("utf-8")
    literal_commands(provider, text, seen)
    for name in sorted(UTILITIES):
        if re.search(r"(?<![A-Za-z0-9_-])" + re.escape(name) + r"(?![A-Za-z0-9_-])", text):
            helper(provider, "/usr/bin/" + name, seen=seen)
    for literal in sorted(set(re.findall(
            r"(/usr/(?:bin|sbin|lib|share)/(?:[A-Za-z0-9_+./-]+))", text))):
        require(".." not in literal.split("/"))
        if literal in INSTALL_HELPERS:
            helper(provider, literal, INSTALL_HELPERS[literal], seen)
        elif literal == "/usr/share/debconf/confmodule":
            helper(provider, literal, "debconf", seen)
    for source in script_sources(text):
        if source in SUPPORT_CONFIG:
            provider.conffile(source)
            continue
        sourced_support(provider, source, raw, seen=seen)
    if not row.get("future", False):
        provider.receipt.setdefault("helper_sources", {})[path] = {
            "sha256": row["sha256"], "package": row["package"],
            "manifest_sha256": row["manifest_sha256"]}


def verify_hooks(provider, raw, conffiles):
    rows = apt_dump(raw)
    verify_environment(rows)
    accepted = []
    for key, value in rows:
        if not re.search(r"(?:Pre|Post)-Invoke|Pre-Install-Pkgs", key):
            continue
        if value == "":
            continue
        require(value in HOOKS and key == HOOKS[value]["key"])
        rule = HOOKS[value]
        owners = [row for row in conffiles.values()
                  if row["package"] == rule["package"] and value in row["text"]]
        require(len(owners) == 1, "tool-origin")
        for path in rule["helpers"]:
            helper(provider, path)
        accepted.append({"key": key, "text_sha256": hashlib.sha256(value.encode()).hexdigest(),
                         "config_sha256": owners[0]["sha256"], "package": rule["package"],
                         "effects": list(rule["effects"])})
    provider.receipt["accepted_apt_hooks"] = accepted
    return accepted


def trigger_database(raw, installed):
    try:
        text = raw.decode("ascii")
    except UnicodeError:
        raise TrustError("inventory-shape") from None
    result = []
    for line in text.splitlines():
        fields = line.split()
        require(len(fields) == 2 and fields[0].startswith("/")
                and ".." not in fields[0].split("/"), "inventory-shape")
        package = fields[1].removesuffix("/noawait")
        require(package in installed, "tool-origin")
        result.append((fields[0], package))
    return result


def affected_triggers(paths, triggers):
    require(len(paths) <= 8192, "file-budget")
    result = set()
    for prefix, package in triggers:
        if any(path == prefix or path.startswith(prefix.rstrip("/") + "/") for path in paths):
            require(package in TRIGGER_HELPERS)
            result.add(package)
    return sorted(result)


def installed_control_basename(provider, package):
    provider.check()
    require(type(package) is str and re.fullmatch(r"[a-z0-9][a-z0-9+.-]{0,127}", package),
            "tool-origin")
    require(all(type(row) is dict for row in provider.installed_records), "tool-origin")
    rows = [row for row in provider.installed_records if row.get("Package") == package]
    require(len(rows) == 1, "tool-origin")
    row = rows[0]
    architecture = row.get("Architecture")
    multiarch = row.get("Multi-Arch", "no")
    version = row.get("Version")
    require(row.get("Status") == "install ok installed"
            and type(version) is str and re.fullmatch(r"[A-Za-z0-9.+:~_-]{1,128}", version)
            and version == provider.installed.get(package)
            and architecture in ("amd64", "all")
            and multiarch in ("no", "same", "foreign", "allowed")
            and not (multiarch == "same" and architecture == "all"), "tool-origin")
    expected = package + ":" + architecture if multiarch == "same" else package
    cached = [(key, value) for key, value in provider.packages.items()
              if type(key) is str and key.split(":")[0] == package]
    require(len(cached) <= 1, "tool-origin")
    if cached:
        key, value = cached[0]
        require(type(value) in (tuple, list) and len(value) == 4, "tool-origin")
        metadata = value[0]
        require(type(metadata) in (tuple, list) and len(metadata) == 5
                and all(type(field) is str for field in metadata)
                and key == metadata[0] == expected
                and metadata[1] == version and metadata[2] == architecture, "tool-origin")
        expected = metadata[0]
    provider.check()
    return expected


def trigger_control(raw):
    try:
        text = raw.decode("ascii")
    except UnicodeError:
        raise TrustError("tool-loader") from None
    for line in text.splitlines():
        fields = line.split()
        if not fields or fields[0].startswith("#"):
            continue
        require(len(fields) == 2 and fields[0] in (
            "interest", "interest-await", "interest-noawait",
            "activate", "activate-await", "activate-noawait"), "tool-loader")
        require(re.fullmatch(r"[A-Za-z0-9+._:/-]+", fields[1])
                and ".." not in fields[1].split("/"), "tool-loader")


def verify_lifecycle(provider, controls, package_paths):
    # Pending/half-configured input would run work outside the captured lock.
    for row in provider.installed_records:
        status = row.get("Status", "")
        require(not any(state in status for state in
                        ("half-", "triggers-", "unpacked", "reinstreq")), "tool-origin")
    raw = provider.protected("/var/lib/dpkg/triggers/File", 1048576)
    triggers = trigger_database(raw, provider.installed)
    activated = affected_triggers(package_paths, triggers)
    file_activations = set()
    named = {}
    for package, data in controls.items():
        if "triggers" not in data:
            continue
        for line in data["triggers"].decode("ascii").splitlines():
            fields = line.split()
            if not fields or fields[0].startswith("#"):
                continue
            require(len(fields) == 2 and fields[0] in (
                "interest", "interest-await", "interest-noawait",
                "activate", "activate-await", "activate-noawait"), "tool-loader")
            if fields[0].startswith("interest") and not fields[1].startswith("/"):
                named.setdefault(fields[1], set()).add(package)
    for data in controls.values():
        for line in data.get("triggers", b"").decode("ascii").splitlines():
            fields = line.split()
            if not fields or not fields[0].startswith("activate"):
                continue
            if len(fields) == 2 and fields[1].startswith("/"):
                require(provider.version in ("8.2", "8.4") and fields[1] in {
                    "/etc/php/" + provider.version + "/" + sapi + "/conf.d"
                    for sapi in ("apache2", "apache2filter", "fpm")}, "tool-loader")
                file_activations.add(fields[1])
                continue
            require(len(fields) == 2 and re.fullmatch("[A-Za-z0-9+._:-]+", fields[1]),
                    "tool-loader")
            state = "/var/lib/dpkg/triggers/" + fields[1]
            if os.path.lexists(state):
                registration = provider.protected(state)
                owners = [token.removesuffix("/noawait")
                          for token in registration.decode("ascii").split()]
                require(owners and all(owner in provider.installed for owner in owners),
                        "tool-origin")
                for owner in owners:
                    require(owner in TRIGGER_HELPERS, "tool-loader")
                    if owner not in activated:
                        activated.append(owner)
            else:
                require(fields[1] in named, "tool-loader")
    trigger_paths = list(package_paths) + sorted(file_activations - set(package_paths))
    activated = sorted(set(activated) | set(affected_triggers(trigger_paths, triggers)))
    for package in activated:
        for path in TRIGGER_HELPERS[package]:
            helper(provider, path)
        # The original installed control script is root-protected dpkg state.
        # Its installed version/source and full bytes are retained before use.
        basename = installed_control_basename(provider, package)
        provider.receipt.setdefault("installed_control_basenames", {})[package] = basename
        script = "/var/lib/dpkg/info/" + basename + ".postinst"
        provider.receipt.setdefault("installed_lifecycle_presence", {})[script] = {
            "exists": os.path.lexists(script), "parents": provider.parent_pins_for_control(script)}
        require(os.path.lexists(script), "tool-origin")
        data = provider.protected(script, 1048576)
        provider.receipt.setdefault("installed_trigger_scripts", {})[package] = {
            "version": provider.installed[package], "sha256": hashlib.sha256(data).hexdigest()}
        script_dependencies(provider, data)
    for package in controls:
        if package not in provider.installed:
            continue
        basename = installed_control_basename(provider, package)
        provider.receipt.setdefault("installed_control_basenames", {})[package] = basename
        for name in ("preinst", "postinst", "prerm", "postrm", "config", "triggers"):
            path = "/var/lib/dpkg/info/" + basename + "." + name
            present = os.path.lexists(path)
            provider.receipt.setdefault("installed_lifecycle_presence", {})[path] = {
                "exists": present, "parents": provider.parent_pins_for_control(path)}
            if not present:
                continue
            data = provider.protected(path, 1048576)
            provider.receipt.setdefault("installed_lifecycle_scripts", {}).setdefault(
                package, {})[name] = {
                    "version": provider.installed[package],
                    "sha256": hashlib.sha256(data).hexdigest(),
                    "authority_role": "verified-retained-installed-control"}
            if name == "triggers":
                trigger_control(data)
            else:
                script_dependencies(provider, data)
    for path, package in INSTALL_HELPERS.items():
        if Path(path).exists():
            helper(provider, path, package)
    descriptors = {}
    for package, data in controls.items():
        descriptors[package] = {
            name: hashlib.sha256(raw).hexdigest() for name, raw in data.items()
            if name in ("preinst", "postinst", "prerm", "postrm", "triggers", "config")}
        for name, raw in data.items():
            if name in ("preinst", "postinst", "prerm", "postrm", "config"):
                script_dependencies(provider, raw)
        if "triggers" in data:
            trigger_control(data["triggers"])
    provider.receipt["accepted_package_lifecycle"] = descriptors
    provider.receipt["activated_installed_triggers"] = activated
    provider.receipt["declared_php_file_trigger_activations"] = sorted(file_activations)
    provider.receipt["accepted_php_lifecycle_effects"] = [
        "selected-cli-alternatives", "ucf-and-ucfr", "selected-module-registration",
        "all-sapi-enable-disable", "php-session-state", "phpsessionclean-service-timer"]


def script_dependencies(provider, raw):
    require(type(raw) is bytes and raw.startswith(b"#!"), "tool-loader")
    interpreter = raw.splitlines()[0].decode("ascii")[2:].strip().split()
    require(interpreter and interpreter[0] in SCRIPT_INTERPRETERS, "tool-loader")
    helper(provider, SCRIPT_INTERPRETERS[interpreter[0]])
    text = raw.decode("utf-8")
    literal_commands(provider, text)
    for name in sorted(UTILITIES):
        if re.search(r"(?<![A-Za-z0-9_-])" + re.escape(name) + r"(?![A-Za-z0-9_-])", text):
            helper(provider, "/usr/bin/" + name)
    for path, package in INSTALL_HELPERS.items():
        if path in text or re.search(r"(?<![A-Za-z0-9_-])" + re.escape(Path(path).name)
                                    + r"(?![A-Za-z0-9_-])", text):
            helper(provider, path, package)
    for source in script_sources(text):
        sourced_support(provider, source, raw)


def sourced_support(provider, source, caller, seen=None):
    if source == "/usr/lib/php/php-helper":
        require(hashlib.sha256(caller).hexdigest() in (
            "6387761716172daa06f6732fcc41997ee640ec7c2c4da6907ac62d9153983986",
            "0e9daa717d1af9b4e069c1976e0aad9cb07141b26b7b9a680009fe322e1e0c89"),
            "tool-loader")
        helper(provider, source, "php-common", seen=seen)
    else:
        require(source in SUPPORT_FILES, "tool-loader")
        helper(provider, source, seen=seen)


def literal_commands(provider, text, seen=None):
    if "/usr/bin/php${version}" in text:
        require(hashlib.sha256(text.encode("utf-8")).hexdigest()
                == "872504901353a3d8291c0980b43e8490580c7aa62ce7607983436a4d9c767649",
                "tool-loader")
        if hasattr(type(provider), "transaction_active") and provider.transaction_active():
            commands = provider.transaction_sessionclean_paths()
        else:
            commands = installed_sessionclean_commands(provider, seen)
        text = text.replace("/usr/bin/php${version}", " ".join(commands))
    if "/usr/sbin/php$CMD" in text:
        require(hashlib.sha256(text.encode("utf-8")).hexdigest()
                == "9a6e7736ba7f69bcbc7fb57bffe1db599b0786d0da03d9b52ccdbae436169dfa",
                "tool-loader")
        text = text.replace("/usr/sbin/php$CMD", "/usr/sbin/phpenmod /usr/sbin/phpdismod")
    require(not re.search(r"/(?:usr/)?(?:bin|sbin)/[A-Za-z0-9_+.-]+[$`]", text),
            "tool-loader")
    for path in sorted(set(re.findall(
            r"(?<![A-Za-z0-9_])(/(?:usr/)?(?:bin|sbin)/[A-Za-z0-9_+.-]+)", text))):
        canonical = "/usr" + path if path.startswith(("/bin/", "/sbin/")) else path
        helper(provider, canonical, seen=seen)


def installed_sessionclean_commands(provider, seen):
    bindings = provider.sessionclean_runtime_bindings()
    require(type(bindings) is dict and len(bindings) <= 64, "file-budget")
    commands = []
    for version, binding in bindings.items():
        require(type(version) is str and re.fullmatch(r"[0-9]\.[0-9]", version)
                and type(binding) is dict
                and binding["binary"] == "/usr/bin/php" + version
                and binding["installed_origin_verified"] is True
                and binding["configuration_verified"] is True, "tool-origin")
        helper(provider, binding["binary"], "php" + version + "-cli", seen=seen)
        commands.append(binding["binary"])
    provider.verify_sessionclean_runtime_bindings()
    return commands


def cache_entries(raw):
    """Decode the glibc new cache format; old/unknown/dynamic variants refuse."""
    magic = b"glibc-ld.so.cache1.1"
    require(type(raw) is bytes and raw.startswith(magic) and len(raw) >= 48)
    count, size = struct.unpack_from("<II", raw, 20)
    require(raw[28] in (0, 2), "tool-loader")
    require(0 < count <= 8192 and 48 + count * 24 + size <= len(raw), "file-budget")
    result = []

    def string(offset):
        require(48 + count * 24 <= offset < 48 + count * 24 + size)
        end = raw.find(b"\x00", offset, 48 + count * 24 + size)
        require(end >= offset)
        return raw[offset:end].decode("ascii")

    for number in range(count):
        _, key, value, _, hwcap = struct.unpack_from("<IIIIQ", raw, 48 + number * 24)
        result.append((string(key), string(value), hwcap))
    return result


def verify_loader(provider):
    if os.path.lexists("/etc/ld.so.preload"):
        require(not provider.protected("/etc/ld.so.preload").strip())
    files = ["/etc/ld.so.conf"] + [
        str(path) for path in sorted(Path("/etc/ld.so.conf.d").iterdir())]
    directories = set()
    for path in files:
        raw = provider.conffile(path)
        for line in raw.decode("ascii").splitlines():
            line = line.split("#", 1)[0].strip()
            if not line or line == "include /etc/ld.so.conf.d/*.conf":
                continue
            require(line.startswith("/") and re.fullmatch(r"/[A-Za-z0-9_/+.-]+", line)
                    and ".." not in line.split("/"))
            require(line.startswith(("/usr/lib", "/lib", "/usr/local/lib")))
            canonical = os.path.realpath(line)
            provider.BASE.parent_pins(canonical + "/placeholder")
            if canonical.startswith("/usr/local/lib"):
                require(not any(
                    ".so" in path.name or path.name == "glibc-hwcaps"
                    for path in Path(canonical).iterdir()))
            directories.add(canonical)
    cache = provider.protected("/etc/ld.so.cache", 16777216)
    entries = cache_entries(cache)
    directories.update(("/usr/lib/x86_64-linux-gnu", "/usr/lib"))
    for name, path, hwcap in entries:
        require(re.fullmatch(r"[A-Za-z0-9_+.-]+", name) and path.startswith("/")
                and ".." not in path.split("/"))
        canonical = os.path.realpath(path)
        require(any(canonical.startswith(directory.rstrip("/") + "/") for directory in directories))
        for entry in provider.files.values():
            for dependency in entry.get("declared_dependencies", []):
                if Path(dependency).name == name:
                    require(hwcap == 0 and canonical == os.path.realpath(dependency))
    provider.receipt["loader_cache_sha256"] = hashlib.sha256(cache).hexdigest()
    return entries
