"""Data-only exact approved-signature selection; never a crypto verdict."""

import base64
import binascii


class SignatureError(Exception):
    pass


def require(value):
    if not value:
        raise SignatureError("inventory-shape")


def length(raw, offset, packet=False):
    require(offset < len(raw))
    first = raw[offset]
    offset += 1
    if first < 192:
        return first, offset
    if first < (224 if packet else 255):
        require(offset < len(raw))
        return ((first - 192) << 8) + raw[offset] + 192, offset + 1
    require(first == 255 and offset + 4 <= len(raw))
    return int.from_bytes(raw[offset:offset + 4], "big"), offset + 4


def fingerprint(signature):
    require(len(signature) >= 6 and signature[:4] == bytes((4, 1, 1, 10)))
    end = 6 + int.from_bytes(signature[4:6], "big")
    require(end + 2 <= len(signature))
    offset, issuers = 6, []
    while offset < end:
        size, offset = length(signature, offset)
        require(size >= 1 and offset + size <= end)
        if signature[offset] & 127 == 33:
            value = signature[offset + 1:offset + size]
            require(len(value) == 21 and value[0] == 4)
            issuers.append(value[1:])
        offset += size
    require(offset == end and len(issuers) == 1)
    return issuers[0]


def select(raw, approved):
    offset, packets, selected = 0, 0, []
    while offset < len(raw):
        require(packets < 8)
        start, header = offset, raw[offset]
        offset += 1
        require(header & 128)
        if header & 64:
            require(header & 63 == 2)
            size, offset = length(raw, offset, packet=True)
        else:
            require((header >> 2) & 15 == 2 and header & 3 != 3)
            width = (1, 2, 4)[header & 3]
            require(offset + width <= len(raw))
            size = int.from_bytes(raw[offset:offset + width], "big")
            offset += width
        require(0 < size <= 8192 and offset + size <= len(raw))
        body = raw[offset:offset + size]
        offset += size
        packets += 1
        if fingerprint(body) == approved:
            selected.append(raw[start:offset])
    require(len(selected) == 1)
    return selected[0], packets


def crc24(raw):
    crc = 0xB704CE
    for byte in raw:
        crc ^= byte << 16
        for _ in range(8):
            crc <<= 1
            if crc & 0x1000000:
                crc ^= 0x1864CFB
    return (crc & 0xFFFFFF).to_bytes(3, "big")


def derive(original, fingerprint_hex):
    require(type(original) is bytes and len(original) <= 1048576
            and original.count(b"-----BEGIN PGP SIGNATURE-----") == 1)
    prefix, armor = original.split(b"-----BEGIN PGP SIGNATURE-----", 1)
    require(prefix.startswith(b"-----BEGIN PGP SIGNED MESSAGE-----\nHash: SHA512\n\n")
            and armor.count(b"-----END PGP SIGNATURE-----") == 1
            and not armor.split(b"-----END PGP SIGNATURE-----", 1)[1].strip())
    require(armor.startswith(b"\n\n"))
    lines = [line.strip() for line in armor.split(b"\n\n", 1)[1]
             .split(b"-----END PGP SIGNATURE-----", 1)[0].splitlines() if line.strip()]
    require(1 <= len(lines) <= 512 and lines[-1].startswith(b"=")
            and not any(line.startswith(b"=") for line in lines[:-1]))
    try:
        raw = base64.b64decode(b"".join(lines[:-1]), validate=True)
        checksum = base64.b64decode(lines[-1][1:], validate=True)
        approved = bytes.fromhex(fingerprint_hex)
    except (ValueError, binascii.Error):
        raise SignatureError("inventory-shape") from None
    require(len(approved) == 20 and checksum == crc24(raw))
    chosen, count = select(raw, approved)
    encoded = base64.b64encode(chosen)
    rendered = b"\n".join(encoded[i:i + 64] for i in range(0, len(encoded), 64))
    derived = (prefix + b"-----BEGIN PGP SIGNATURE-----\n\n" + rendered
               + b"\n=" + base64.b64encode(crc24(chosen))
               + b"\n-----END PGP SIGNATURE-----\n")
    return derived, prefix, chosen, count
