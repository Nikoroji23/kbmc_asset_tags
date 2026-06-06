# Sample Import Example - NEW FORMAT (ASSET TAG, TYPE, PC NAME, IP ADDRESS, STATUS, ASSIGNED TO)

## CSV Format (New Format - For Creating Devices):
```
ASSET TAG,TYPE,PC NAME,IP ADDRESS,STATUS,ASSIGNED TO
KBM-LAP-2024-001,Laptop,PC-ADMIN-01,192.168.1.10,deployed,Gracie Ortuzar
KBM-MON-2024-001,Monitor,,192.168.1.20,deployed,Gracie Ortuzar
KBM-KEY-2024-001,Keyboard,,,deployed,Gracie Ortuzar
KBM-MOUSE-2024-001,Mouse,,,deployed,Gracie Ortuzar
KBM-LAP-2024-002,Laptop,,192.168.1.11,deployed,Alfonso Annias
KBM-MON-2024-002,Monitor,,192.168.1.21,deployed,Alfonso Annias
KBM-KEY-2024-002,Keyboard,,,deployed,Alfonso Annias
KBM-SYS-2024-001,System Unit,PC-IT-01,192.168.1.50,deployed,
KBM-PRT-2024-001,Printer,,192.168.1.100,in_stock,
KBM-DES-2024-001,Desktop,PC-SALES-01,192.168.1.15,deployed,
KBM-UPS-2024-001,UPS,,,in_stock,
KBM-CHAR-2024-001,Charger,,,in_stock,
```

---

## What Each Column Means:

| Column | Required | Description | Example |
|--------|----------|-------------|---------|
| **ASSET TAG** | No* | Unique device identifier | KBM-LAP-2024-001 |
| **TYPE** | ✅ YES | Device type (see list below) | Laptop, Monitor, Keyboard |
| **PC NAME** | No | PC/System name for linking | PC-ADMIN-01, PC-SALES-01 |
| **IP ADDRESS** | No | Device IP address | 192.168.1.10 |
| **STATUS** | No | Device status (defaults to deployed) | in_stock, deployed, under_repair |
| **ASSIGNED TO** | No | Employee full name | Gracie Ortuzar, Alfonso Annias |

*Asset tag can be blank or left as N/A

---

## What Happens During Import - Row by Row:

### Row 1: KBM-LAP-2024-001 ✅ Complete Laptop
```
Input:   ASSET TAG=KBM-LAP-2024-001, TYPE=Laptop, PC NAME=PC-ADMIN-01, IP ADDRESS=192.168.1.10, STATUS=deployed, ASSIGNED TO=Gracie Ortuzar
Process: All fields present, device created
Result:  ✅ Laptop device created and assigned to Gracie Ortuzar
```

### Row 2: KBM-MON-2024-001 ⚠️ Monitor with Blank PC NAME (Smart Link!)
```
Input:   ASSET TAG=KBM-MON-2024-001, TYPE=Monitor, PC NAME=[blank], IP ADDRESS=192.168.1.20, STATUS=deployed, ASSIGNED TO=Gracie Ortuzar
Process:
  1. PC NAME is blank
  2. User "Gracie Ortuzar" assigned
  3. Check if Gracie has Laptop → YES (KBM-LAP-2024-001)
  4. Auto-link: PC NAME = "KBM-LAP-2024-001"
Result:  ✅ Monitor created and auto-linked to Gracie's Laptop
DB:      pc_name=KBM-LAP-2024-001 (auto-filled!)
```

### Row 3: KBM-KEY-2024-001 ⚠️ Keyboard with Blank PC NAME & IP
```
Input:   ASSET TAG=KBM-KEY-2024-001, TYPE=Keyboard, PC NAME=[blank], IP ADDRESS=[blank], STATUS=deployed, ASSIGNED TO=Gracie Ortuzar
Process:
  1. PC NAME blank → auto-link to Gracie's Laptop → "KBM-LAP-2024-001"
  2. IP ADDRESS blank → stored as NULL
Result:  ✅ Keyboard created, linked to Laptop, no IP
DB:      pc_name=KBM-LAP-2024-001, ip_address=NULL
```

### Row 4: KBM-MOUSE-2024-001 ⚠️ Mouse Multiple Blanks
```
Input:   ASSET TAG=KBM-MOUSE-2024-001, TYPE=Mouse, PC NAME=[blank], IP ADDRESS=[blank], STATUS=deployed, ASSIGNED TO=Gracie Ortuzar
Process:
  1. PC NAME blank → auto-link to Gracie's Laptop → "KBM-LAP-2024-001"
  2. IP ADDRESS blank → NULL
Result:  ✅ Mouse created, linked to Gracie's Laptop
```

### Row 5: KBM-LAP-2024-002 ⚠️ Laptop without PC NAME
```
Input:   ASSET TAG=KBM-LAP-2024-002, TYPE=Laptop, PC NAME=[blank], IP ADDRESS=192.168.1.11, STATUS=deployed, ASSIGNED TO=Alfonso Annias
Process:
  1. Device type is Laptop (itself a main device)
  2. PC NAME blank remains blank
  3. Alfonso's laptop doesn't have a PC name yet
Result:  ✅ Laptop created for Alfonso without PC name
```

### Row 6: KBM-MON-2024-002 ✅ Monitor Linked to Alfonso's Laptop
```
Input:   ASSET TAG=KBM-MON-2024-002, TYPE=Monitor, PC NAME=[blank], IP ADDRESS=192.168.1.21, STATUS=deployed, ASSIGNED TO=Alfonso Annias
Process:
  1. PC NAME blank
  2. Check if Alfonso has Laptop → YES (KBM-LAP-2024-002 - just imported!)
  3. Auto-link: PC NAME = "KBM-LAP-2024-002"
Result:  ✅ Monitor auto-linked to Alfonso's Laptop
DB:      pc_name=KBM-LAP-2024-002 (auto-filled!)
```

### Row 7: KBM-KEY-2024-002 ✅ Keyboard Auto-Linked
```
Auto-linked to Alfonso's Laptop (KBM-LAP-2024-002)
```

### Row 8: KBM-SYS-2024-001 ⚠️ System Unit with No Assignment
```
Input:   ASSET TAG=KBM-SYS-2024-001, TYPE=System Unit, PC NAME=PC-IT-01, IP ADDRESS=192.168.1.50, STATUS=deployed, ASSIGNED TO=[blank]
Process: All fields filled except assignment
Result:  ✅ System Unit created, unassigned
```

### Row 9: KBM-PRT-2024-001 ⚠️ Printer In Stock
```
Input:   ASSET TAG=KBM-PRT-2024-001, TYPE=Printer, PC NAME=[blank], IP ADDRESS=192.168.1.100, STATUS=in_stock, ASSIGNED TO=[blank]
Process: In-stock device with IP
Result:  ✅ Printer created in stock, unassigned
```

### Row 10-12: Desktop, UPS, Charger
```
Various combinations of assigned/unassigned and complete/partial data
```

---

## Import Summary:

✅ **Devices Created:** 12
✅ **Assignments Created:** 7 (Gracie, Alfonso, unassigned items)
✅ **Auto-linked Peripherals:** 5 (Monitor, Keyboard, Keyboard automatically linked to user's Laptop)
✅ **In Stock:** 3 (Printer, UPS, Charger)
✅ **Deployed:** 9

---

## Supported Device Types:

- Laptop
- Desktop
- Monitor
- Keyboard
- Mouse
- Printer
- System Unit
- Charger
- UPS
- Storage Device
- Network Equipment
- Network Switch
- Server
- Phone
- Tablet
- Peripherals
- Other

---

## Supported Statuses:

- `in_stock` - Device in inventory
- `deployed` - Device assigned to user (default)
- `under_repair` - Device being repaired
- `retired` - Device end-of-life
- `disposed` - Device disposed
- `pending_inspection` - Waiting for inspection
- `rejected` - Failed inspection

---

## Key Features:

✅ **Smart PC Name Auto-Linking** - Peripherals auto-link to user's primary device (Laptop)
✅ **Automatic N/A Handling** - Blank cells converted to NULL in database
✅ **User-Centric Assignment** - Assign to employee by full name
✅ **Flexible Asset Tags** - Can be blank for unassigned devices
✅ **Duplicate Prevention** - Updates existing devices if asset tag matches
✅ **Type Auto-Creation** - Creates device types if they don't exist
✅ **Sequential Processing** - Works even if Laptop imported before peripherals
