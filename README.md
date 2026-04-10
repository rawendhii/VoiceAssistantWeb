# 🎙️ Voice Assistant for Digital Accessibility

## 📌 Project Overview

This project is a **desktop-inspired web application** built with **Symfony (Twig + MySQL)**, designed to improve **digital accessibility** for users with disabilities, particularly:

- 👁️‍🗨️ visually impaired users
- ✋ users with limited or no hand mobility

The system allows users to interact with the application using **voice commands**, reducing the need for keyboard or mouse input.

---

## 🎯 Objectives

- Provide an **accessible interface** using voice interaction
- Implement a **secure and structured system** (Front Office & Back Office)
- Demonstrate a **CRUD-based architecture**
- Prepare the system for **AI integration (voice recognition & automation)**

---

## 🏗️ Project Architecture

The application follows a clear separation:

### 🔹 Front Office (User)
- Voice interaction interface
- Profile management
- File management
- Voice commands list

### 🔹 Back Office (Admin)
- User management
- Role management
- Voice command management
- File management
- Command history tracking

---

## 🧩 Main Features

### 👤 Authentication
- Login / Logout
- Role-based access control (USER / ADMIN)

### 🎤 Voice Commands
- Capture voice input (Web Speech API)
- Convert speech → text
- Match commands with predefined keywords
- Execute actions (navigation, file actions, etc.)

### 📂 File Management (CRUD)
- Create, edit, delete, list files
- Linked to users

### 📜 Command History
- Track all executed voice commands
- Status: SUCCESS / FAILED
- Admin monitoring

### 🛡️ Role Management
- Dynamic roles stored in database
- Access control via Symfony security

---

## 🗃️ Database Structure

Each module respects the requirement:

> ✅ **Minimum: 2 entities + 1 relationship**

### Entities:
- `User`
- `Role`
- `ManagedFile`
- `VoiceCommand`
- `CommandHistory`

### Relationships:
- User → Role (ManyToOne)
- User → ManagedFile (OneToMany)
- User → CommandHistory (OneToMany)
- CommandHistory → VoiceCommand (ManyToOne)

---

## 🧠 Technologies Used

- **Symfony 6+**
- **Twig (Template Engine)**
- **MySQL**
- **Doctrine ORM**
- **JavaScript (Voice Recognition API)**
- **Lucide Icons**
- **HTML/CSS**

---

## 🎨 UI / Templates

- Custom **Front Office** and **Back Office templates**
- Reusable layouts:
  - `admin/layout.html.twig`
  - `front/layout.html.twig`
- Responsive and clean design
- Authentication pages (Login / Register) with modern UI

---

## ♿ Accessibility Features

- Voice-based interaction (no keyboard required)
- Simple and clear interface
- High contrast UI
- Large clickable elements
- Audio feedback (planned / extendable)

---

## 🚀 Installation

### 1. Clone the project

```bash
git clone  https://github.com/rawendhii/VoiceAssistantWeb.git
cd VoiceAssistantWeb
