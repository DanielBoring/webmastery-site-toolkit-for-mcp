"""Read-only closure of the default package CA bundle; no helper execution."""

import base64
import binascii
import errno
import hashlib
import json
import os
import re
import stat


CONFIG = "/etc/ca-certificates.conf"
BUNDLE = "/etc/ssl/certs/ca-certificates.crt"
CERTS = "/etc/ssl/certs"
SHARE = "/usr/share/ca-certificates"
MOZILLA = SHARE + "/mozilla"
LOCAL = "/usr/local/share/ca-certificates"
ANCHOR_LIMIT = 65536
BUNDLE_LIMIT = 4194304
ENTRY_LIMIT = 2048
ANCHOR_NAME = re.compile(r"mozilla/[A-Za-z0-9][A-Za-z0-9_.-]{0,191}\.crt")
NETLOCK_ANCHOR = "mozilla/NetLock_Arany_=Class_Gold=_F\u0151tan\u00fas\u00edtv\u00e1ny.crt"
HASH_NAME = re.compile(r"([0-9a-f]{8})\.(0|[1-9][0-9]{0,2})")
HEX = re.compile(r"[0-9a-f]{64}")
GENERATION_HASHES = {
    "postinst": "bbad2df5d3c5e3d787789275830bc5b597fe6e9218a99516947a079dcae9e262",
    "templates": "f7319e443bb53f4b9ac71ddbd4fc7b6b9197a0415be067803ab56806cf3ee762",
    "config_skeleton": "e33b2a175d0f29e2d5c0bff198ef43f7a349995c989a1e02dc6422aca2735ebd",
}


def _digest(raw):
    return hashlib.sha256(raw).hexdigest()


def _json(value):
    return json.dumps(value, sort_keys=True, separators=(",", ":")).encode("ascii")


def _require(provider, condition, reason="tool-origin", *, origin_check=None):
    provider.BASE.require(condition, reason, origin_check=origin_check)


def _no_caps(provider, path):
    provider.check()
    _require(provider, hasattr(os, "getxattr"), "tool-identity")
    try:
        value = os.getxattr(path, "security.capability", follow_symlinks=False)
    except OSError as error:
        _require(provider, error.errno == errno.ENODATA, "tool-identity")
    else:
        # Even an empty capability attribute is not an absent attribute.
        _require(provider, False, "tool-identity")


def _directory(provider, path, retain, phase, optional=False):
    provider.check()
    parents = provider.BASE.parent_pins(path)
    try:
        info = os.lstat(path)
    except FileNotFoundError:
        _require(provider, optional, "tool-parent")
        result = {"present": False, "parents": parents, "entries": {}}
        retain("directory-" + phase + "-" + _digest(path.encode())[:16], _json(result))
        return result
    _require(provider, stat.S_ISDIR(info.st_mode) and info.st_uid == 0
             and not info.st_mode & 0o6022 and os.path.realpath(path) == path, "tool-parent")
    _no_caps(provider, path)
    before = provider.BASE.identity(info)
    entries = {}
    with os.scandir(path) as iterator:
        for entry in iterator:
            provider.check()
            _require(provider, len(entries) < ENTRY_LIMIT, "file-budget")
            _require(provider, entry.name not in entries and "/" not in entry.name
                     and 0 < len(entry.name) <= 255, "tool-link")
            child = path + "/" + entry.name
            current = os.lstat(child)
            pin = {"identity": provider.BASE.identity(current)}
            if stat.S_ISLNK(current.st_mode):
                pin["target"] = os.readlink(child)
                _require(provider, len(pin["target"]) <= 4096, "file-budget")
            _require(provider, provider.BASE.identity(os.lstat(child)) == pin["identity"],
                     "tool-link")
            entries[entry.name] = pin
    _require(provider, provider.BASE.identity(os.lstat(path)) == before
             and provider.BASE.parent_pins(path) == parents, "tool-parent")
    result = {"present": True, "identity": before, "parents": parents,
              "entries": dict(sorted(entries.items()))}
    retain("directory-" + phase + "-" + _digest(path.encode())[:16], _json(result))
    for name, pin in entries.items():
        netlock = NETLOCK_ANCHOR.split("/")[1]
        named_anchor = (path == MOZILLA and name == netlock
                        or path == CERTS and name == netlock[:-4] + ".pem")
        _require(provider, re.fullmatch(r"[A-Za-z0-9_.-]{1,224}", name) is not None
                 or named_anchor,
                 "tool-link")
        current = pin["identity"]
        _require(provider, current["uid"] == 0 and not current["mode"] & 0o6000,
                 "tool-identity")
        if stat.S_ISLNK(current["mode"]):
            _require(provider, current["nlink"] == 1, "tool-link")
        else:
            _require(provider, not current["mode"] & 0o022, "tool-identity")
        _no_caps(provider, path + "/" + name)
    return result


def _bound(provider, path, package):
    provider.check()
    entry = provider.bound_file(path, expected_package=package)
    _require(provider, type(entry) is dict and all(
        name in entry for name in ("raw", "canonical", "identity", "sha256", "package",
                                  "manifest_sha256")), "inventory-shape")
    raw, metadata, identity = entry["raw"], entry["package"], entry["identity"]
    _require(provider, type(raw) is bytes and 0 < len(raw) <= BUNDLE_LIMIT, "file-budget")
    _require(provider, type(metadata) in (tuple, list) and len(metadata) == 5
             and all(type(field) is str and re.fullmatch(r"[A-Za-z0-9.:+~_-]{1,128}", field)
                     for field in metadata), "tool-origin", origin_check="ca-bound-metadata")
    _require(provider, metadata[0].split(":")[0] == package and metadata[3] == package,
             origin_check="ca-bound-source")
    _require(provider, type(identity) is dict and all(
        key in identity and type(identity[key]) is int for key in (
            "dev", "ino", "uid", "gid", "mode", "nlink", "size", "mtime_ns", "ctime_ns")),
        "inventory-shape")
    _require(provider, stat.S_ISREG(identity["mode"])
             and identity["uid"] == 0 and identity["nlink"] == 1
             and not identity["mode"] & 0o6022 and identity["size"] == len(raw),
             "tool-identity")
    _require(provider, type(entry["canonical"]) is str
             and os.path.realpath(path) == entry["canonical"]
             and provider.BASE.identity(os.lstat(entry["canonical"])) == identity, "tool-link")
    _require(provider, type(entry["sha256"]) is str and HEX.fullmatch(entry["sha256"])
             and entry["sha256"] == _digest(raw) and type(entry["manifest_sha256"]) is str
             and HEX.fullmatch(entry["manifest_sha256"]), "tool-origin",
             origin_check="ca-bound-digest")
    _no_caps(provider, entry["canonical"])
    return dict(entry, package=list(metadata),
                parents=provider.BASE.parent_pins(entry["canonical"]))


def _tlv(provider, raw, offset):
    _require(provider, offset + 2 <= len(raw), "inventory-shape")
    tag, length = raw[offset], raw[offset + 1]
    start = offset + 2
    _require(provider, tag & 31 != 31, "inventory-shape")
    if length & 128:
        count = length & 127
        _require(provider, 1 <= count <= 3 and start + count <= len(raw)
                 and raw[start] != 0, "inventory-shape")
        length = int.from_bytes(raw[start:start + count], "big")
        _require(provider, length >= 128, "inventory-shape")
        start += count
    _require(provider, start + length <= len(raw), "inventory-shape")
    return tag, start, start + length


def _certificate(provider, raw):
    _require(provider, 0 < len(raw) <= ANCHOR_LIMIT, "file-budget")
    match = re.fullmatch(
        rb"-----BEGIN CERTIFICATE-----\n((?:[A-Za-z0-9+/]{1,64}\n)*"
        rb"[A-Za-z0-9+/]{1,64}={0,2}\n)-----END CERTIFICATE-----\n", raw)
    _require(provider, match is not None, "inventory-shape")
    encoded = match[1].replace(b"\n", b"")
    try:
        der = base64.b64decode(encoded, validate=True)
    except (ValueError, binascii.Error):
        _require(provider, False, "inventory-shape")
    _require(provider, base64.b64encode(der) == encoded, "inventory-shape")
    tag, start, end = _tlv(provider, der, 0)
    _require(provider, tag == 48 and end == len(der), "inventory-shape")
    children = []
    while start < end:
        child = _tlv(provider, der, start)
        children.append(child)
        start = child[2]
        _require(provider, len(children) <= 3, "inventory-shape")
    _require(provider, [child[0] for child in children] == [48, 48, 3], "inventory-shape")
    tbs_start, tbs_end = children[0][1:]
    fields = []
    while tbs_start < tbs_end:
        field = _tlv(provider, der, tbs_start)
        _require(provider, field[2] <= tbs_end and len(fields) < 10, "inventory-shape")
        fields.append(field)
        tbs_start = field[2]
    if fields and fields[0][0] == 160:
        version = _tlv(provider, der, fields[0][1])
        _require(provider, version[0] == 2 and version[2] == fields[0][2]
                 and der[version[1]:version[2]] in (b"\x00", b"\x01", b"\x02"),
                 "inventory-shape")
        fields = fields[1:]
    _require(provider, 6 <= len(fields) <= 9 and [f[0] for f in fields[:6]] ==
             [2, 48, 48, 48, 48, 48], "inventory-shape")
    serial = der[fields[0][1]:fields[0][2]]
    _require(provider, 1 <= len(serial) <= 21 and not serial[0] & 128
             and (len(serial) == 1 or serial[0] != 0 or serial[1] & 128),
             "inventory-shape")
    _require(provider, len({f[0] for f in fields[6:]}) == len(fields[6:])
             and all(f[0] in (129, 130, 163) for f in fields[6:]), "inventory-shape")
    algorithm = _tlv(provider, der, children[1][1])
    _require(provider, algorithm[0] == 6 and algorithm[1] < algorithm[2]
             and algorithm[2] <= children[1][2], "inventory-shape")
    _require(provider, der[fields[1][1]:fields[1][2]] ==
             der[children[1][1]:children[1][2]], "inventory-shape")
    signature = der[children[2][1]:children[2][2]]
    _require(provider, len(signature) >= 2 and signature[0] <= 7
             and signature[-1] & ((1 << signature[0]) - 1) == 0, "inventory-shape")
    # Package provenance authenticates the certificate; DER framing excludes
    # extra PEM objects/trailing data without invoking OpenSSL or loading a new library.
    return _digest(der)


def _configuration(provider, raw):
    _require(provider, type(raw) is bytes and 0 < len(raw) <= 65536
             and raw.endswith(b"\n") and b"\r" not in raw, "inventory-shape")
    try:
        lines = raw.decode("utf-8").splitlines()
    except UnicodeDecodeError:
        _require(provider, False, "inventory-shape")
    selected, disabled, seen = [], [], set()
    for line in lines:
        provider.check()
        if not line or line.startswith("#"):
            continue
        off = line.startswith("!")
        name = line[1:] if off else line
        _require(provider, (ANCHOR_NAME.fullmatch(name) is not None or name == NETLOCK_ANCHOR)
                 and name not in seen,
                 "inventory-shape")
        _require(provider, len(seen) < 512, "file-budget")
        seen.add(name)
        (disabled if off else selected).append(name)
    _require(provider, bool(selected), "inventory-shape")
    return selected, disabled


def verify_generation(provider, raw, helper, policy, trust):
    """Bind the generated default to installed bootstrap controls and debconf."""
    provider.check()
    package = list(helper["package"])
    installed = [row for row in provider.installed_records
                 if row.get("Package") == "ca-certificates"]
    _require(provider, len(installed) == 1
             and installed[0].get("Status") == "install ok installed"
             and installed[0].get("Version") == package[1]
             and installed[0].get("Architecture") == package[2]
             and installed[0].get("Source", "ca-certificates") in (
                 "ca-certificates", "ca-certificates (" + package[4] + ")")
             and package[0] == package[3] == "ca-certificates"
             and package[1] == package[4], "tool-origin", origin_check="ca-installed-generation")
    controls = {}
    for name in ("postinst", "config", "templates"):
        path = "/var/lib/dpkg/info/ca-certificates." + name
        controls[name] = provider.protected(path, 131072)
    for name in ("postinst", "templates"):
        _require(provider, _digest(controls[name]) == GENERATION_HASHES[name], "tool-loader")
    lists = list(re.finditer(rb'(?m)^CERTS_LIST="([^"\n]*)"$', controls["config"]))
    _require(provider, len(lists) == 1, "tool-loader")
    literal = lists[0][1]
    names = literal.split(b", ")
    _require(provider, b", ".join(names) == literal, "inventory-shape")
    selected, disabled = _configuration(provider, b"\n".join(names) + b"\n")
    _require(provider, not disabled and selected == sorted(selected, key=lambda s: s.encode("utf-8")),
             "tool-origin", origin_check="ca-generated-selection")
    skeleton = (controls["config"][:lists[0].start()] + b'CERTS_LIST=""'
                + controls["config"][lists[0].end():])
    _require(provider, _digest(skeleton) == GENERATION_HASHES["config_skeleton"], "tool-loader")
    _, manifest_path, manifest_info, manifest = provider.packages["ca-certificates"]
    _, live_info, live_manifest = provider.read(manifest_path, limit=4194304)
    _require(provider, provider.BASE.identity(live_info) == provider.BASE.identity(manifest_info)
             and live_manifest == manifest and _digest(manifest) == helper["manifest_sha256"],
             "tool-origin", origin_check="ca-generation-manifest")
    manifest_names = []
    prefix = (SHARE.lstrip("/") + "/").encode("ascii")
    for line in manifest.splitlines():
        fields = line.split(b"  ", 1)
        _require(provider, len(fields) == 2 and re.fullmatch(rb"[0-9a-f]{32}", fields[0]),
                 "tool-origin", origin_check="ca-manifest-row")
        if fields[1].startswith(prefix):
            try:
                name = fields[1][len(prefix):].decode("utf-8", errors="strict")
            except UnicodeError:
                _require(provider, False, "tool-origin", origin_check="ca-manifest-name-encoding")
            _require(provider, name not in manifest_names and name in selected, "tool-origin",
                     origin_check="ca-manifest-name")
            manifest_names.append(name)
    _require(provider, set(manifest_names) == set(selected), "tool-origin",
             origin_check="ca-manifest-domain")
    marker = b"cat > /etc/ca-certificates.conf <<EOF\n"
    _require(provider, controls["postinst"].count(marker) == 1, "tool-loader")
    header = controls["postinst"].split(marker, 1)[1].split(b"\nEOF\n", 1)[0] + b"\n"
    _require(provider, raw == header + b"\n".join(names) + b"\n", "tool-origin",
             origin_check="ca-generated-content")
    debconf = provider.conffile("/etc/debconf.conf")
    logical = b"\n".join(line for line in debconf.splitlines()
                         if not line.lstrip().startswith(b"#"))
    databases = policy.deb822(logical)
    _require(provider, databases and databases[0] == {
        "Config": "configdb", "Templates": "templatedb"}, "tool-loader")
    expected = {
        "config": ("File", "/var/cache/debconf/config.dat"),
        "passwords": ("File", "/var/cache/debconf/passwords.dat"),
        "configdb": ("Stack", "config, passwords"),
        "templatedb": ("File", "/var/cache/debconf/templates.dat"),
    }
    observed = {}
    for row in databases[1:]:
        name = row.get("Name")
        _require(provider, name in expected and name not in observed
                 and row.get("Driver") == expected[name][0], "tool-loader")
        key = "Stack" if name == "configdb" else "Filename"
        _require(provider, row.get(key) == expected[name][1]
                 and set(row) <= {"Name", "Driver", key, "Mode", "Backup", "Required"},
                 "tool-loader")
        if "Mode" in row:
            _require(provider, row["Mode"] == ("600" if name == "passwords" else "644"),
                     "tool-loader")
        for flag in ("Backup", "Required"):
            _require(provider, flag not in row or row[flag] in ("true", "false"), "tool-loader")
        observed[name] = row
    _require(provider, set(observed) == set(expected), "tool-loader")
    state = provider.protected("/var/cache/debconf/config.dat", 4194304)
    templates = provider.protected("/var/cache/debconf/templates.dat", 4194304)
    state_rows = policy.deb822(state)
    template_rows = policy.deb822(templates, debconf=True)

    def one(rows, name):
        matches = [row for row in rows if row.get("Name") == name]
        _require(provider, len(matches) == 1, "tool-origin", origin_check="ca-debconf-row")
        return matches[0]

    available = ", ".join(selected)
    for name, value in (("trust_new_crts", "yes"), ("enable_crts", available)):
        question = "ca-certificates/" + name
        row = one(state_rows, question)
        _require(provider, row.get("Template") == question and row.get("Owners") == "ca-certificates"
                 and row.get("Value") == value, "tool-origin", origin_check="ca-debconf-value")
        if name == "enable_crts":
            _require(provider, row.get("Variables", "").strip() == "enable_crts = " + available,
                     "tool-origin", origin_check="ca-debconf-variables")
        source = [entry for entry in policy.deb822(controls["templates"], debconf=True)
                  if entry.get("Template") == question]
        _require(provider, len(source) == 1, "tool-origin", origin_check="ca-debconf-source")
        template = one(template_rows, question)
        _require(provider, template.get("Owners") == question
                 and all(template.get(key) == value for key, value in source[0].items()
                         if key != "Template"), "tool-origin", origin_check="ca-debconf-template")
    for name in ("postinst", "config"):
        trust.script_dependencies(provider, controls[name])
    for path, owner in (
        ("/usr/bin/dpkg-statoverride", "dpkg"), ("/usr/bin/chgrp", "coreutils"),
        ("/usr/bin/egrep", "grep"), ("/usr/sbin/update-ca-certificates", "ca-certificates"),
        ("/usr/share/debconf/frontend", "debconf"),
    ):
        trust.helper(provider, path, owner)
    for driver in ("DbDriver", "DbDriver/File", "DbDriver/Stack", "Config", "Db", "Question", "Template"):
        entry = provider.bound_file("/usr/share/perl5/Debconf/" + driver + ".pm", "debconf")
        trust.perl_support(provider, entry["raw"], set())
    for name, before in controls.items():
        _require(provider, provider.protected("/var/lib/dpkg/info/ca-certificates." + name,
                                             131072) == before, "tool-link")
    _require(provider, provider.conffile("/etc/debconf.conf") == debconf
             and provider.protected("/var/cache/debconf/config.dat", 4194304) == state
             and provider.protected("/var/cache/debconf/templates.dat", 4194304) == templates,
             "tool-link")
    _require(provider, provider.bound_file("/usr/sbin/update-ca-certificates",
                                          "ca-certificates") == helper, "tool-origin",
             origin_check="ca-generation-helper")
    provider.check()
    return {"schema": "installed-default-ca-generation-v1",
            "trust_boundary": "unchanged-protected-initial-dpkg-bootstrap",
            "package": package, "manifest_sha256": _digest(manifest),
            "control_sha256": {name: _digest(data) for name, data in controls.items()},
            "debconf_config_sha256": _digest(debconf), "state_sha256": _digest(state),
            "templates_sha256": _digest(templates), "selection_sha256": _digest(raw),
            "default_enabled_anchors": len(selected), "helper_execution_performed": False,
            "signed_archive_verification_performed": False}


def verify_ca(provider):
    """Use only the caller's retained acquisitions and existing deadline/ledgers."""
    provider.check()
    sequence = getattr(provider, "_ca_closure_sequence", 0)
    _require(provider, type(sequence) is int and sequence >= 0, "inventory-shape")
    provider._ca_closure_sequence = sequence + 1

    def retain(name, raw):
        return provider.retain("ca-" + str(sequence + 1) + "-" + name, raw)

    initial = {path: _directory(provider, path, retain, "before", optional=path == LOCAL)
               for path in (SHARE, MOZILLA, LOCAL, CERTS)}
    _require(provider, set(initial[SHARE]["entries"]) == {"mozilla"}, "tool-link")
    _require(provider, not initial[LOCAL]["entries"], "tool-origin", origin_check="ca-local-domain")
    config = provider.conffile(CONFIG)
    retain("default-config", config)
    selected, disabled = _configuration(provider, config)
    _require(provider, set(initial[MOZILLA]["entries"]) ==
             {name.split("/")[1] for name in selected + disabled}, "tool-link")

    helper = _bound(provider, "/usr/sbin/update-ca-certificates", "ca-certificates")
    _require(provider, helper["canonical"] == "/usr/sbin/update-ca-certificates"
             and helper["raw"].startswith(b"#!/bin/sh\n"), "tool-loader")
    interpreter = _bound(provider, "/bin/sh", "dash")
    openssl = _bound(provider, "/usr/bin/openssl", "openssl")
    for entry in (interpreter, openssl):
        provider.system(entry["canonical"])
        provider.origin(entry["canonical"])
        provider.dependencies(entry["canonical"])
    bindings = {
        "/usr/sbin/update-ca-certificates": helper,
        "/bin/sh": interpreter,
        "/usr/bin/openssl": openssl,
    }
    anchors, enabled, expected = [], {}, bytearray()
    for name in selected + disabled:
        path = SHARE + "/" + name
        entry = _bound(provider, path, "ca-certificates")
        _require(provider, entry["canonical"] == path, "tool-link")
        _require(provider, entry["package"] == helper["package"]
                 and entry["manifest_sha256"] == helper["manifest_sha256"], "tool-origin",
                 origin_check="ca-anchor-association")
        der_sha = _certificate(provider, entry["raw"])
        bindings[path] = entry
        active = name in selected
        if active:
            _require(provider, len(expected) + len(entry["raw"]) <= BUNDLE_LIMIT, "file-budget")
            expected.extend(entry["raw"])
            enabled[name.split("/")[1][:-4] + ".pem"] = path
        anchors.append({"name": name, "enabled": active, "sha256": entry["sha256"],
                        "der_sha256": der_sha})
    bundle = provider.protected(BUNDLE, limit=BUNDLE_LIMIT)
    _require(provider, bundle == bytes(expected), "tool-origin", origin_check="ca-bundle-content")
    links, pem_names, hashes, covered = [], set(), {}, set()
    for name, pin in initial[CERTS]["entries"].items():
        provider.check()
        mode = pin["identity"]["mode"]
        if name == "ca-certificates.crt":
            _require(provider, stat.S_ISREG(mode) and pin["identity"]["nlink"] == 1,
                     "tool-link")
            continue
        _require(provider, stat.S_ISLNK(mode), "tool-link")
        if name in enabled:
            _require(provider, pin["target"] == enabled[name], "tool-link")
            pem_names.add(name)
            source = enabled[name]
        else:
            match = HASH_NAME.fullmatch(name)
            _require(provider, match is not None and pin["target"] in enabled, "tool-link")
            hashes.setdefault(match[1], []).append(int(match[2]))
            covered.add(pin["target"])
            source = enabled[pin["target"]]
        _require(provider, os.path.realpath(CERTS + "/" + name) == source, "tool-link")
        links.append({"name": name, "target": pin["target"],
                      "source_sha256": bindings[source]["sha256"]})
    _require(provider, "ca-certificates.crt" in initial[CERTS]["entries"]
             and pem_names == set(enabled) and covered == set(enabled), "tool-link")
    _require(provider, all(sorted(indices) == list(range(len(indices)))
                           for indices in hashes.values()), "tool-link")
    retain("aliases", _json(links))
    for path, before in bindings.items():
        current = _bound(provider, path, before["package"][0].split(":")[0])
        _require(provider, current == before, "tool-link")
    _require(provider, provider.conffile(CONFIG) == config
             and provider.protected(BUNDLE, limit=BUNDLE_LIMIT) == bundle, "tool-link")
    for path, before in initial.items():
        _require(provider, _directory(provider, path, retain, "after", optional=path == LOCAL) == before,
                 "tool-link")
    provider.check()
    result = {
        "schema": "package-ca-closure-v1", "verified": True,
        "config_sha256": _digest(config), "bundle_sha256": _digest(bundle),
        "package": helper["package"], "manifest_sha256": helper["manifest_sha256"],
        "anchors": anchors, "aliases_sha256": _digest(_json(links)),
        "alias_count": len(links), "local_anchors_present": False,
        "generation_helpers": [
            {"name": name, "canonical": entry["canonical"], "sha256": entry["sha256"],
             "package": entry["package"], "manifest_sha256": entry["manifest_sha256"]}
            for name, entry in bindings.items() if name in (
                "/usr/sbin/update-ca-certificates", "/bin/sh", "/usr/bin/openssl")
        ],
        "helper_execution_performed": False,
        "hash_alias_subject_digest_recomputed": False,
    }
    retain("closure-result", _json(result))
    provider.check()
    return result
