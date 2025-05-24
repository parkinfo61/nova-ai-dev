package main

import (
    "fmt"
    "log"
    "os"
    "path/filepath"
    "strings"

    tgbotapi "github.com/go-telegram-bot-api/telegram-bot-api"
)

const botToken = "6728031451:AAGVOKYz7BU5fLK2NB4dW6eSEaLW15x2x1A"
const geojsonDir = "../output/geojson" // путь до папки с geojson-файлами

func main() {
    bot, err := tgbotapi.NewBotAPI(botToken)
    if err != nil {
        log.Panic(err)
    }

    bot.Debug = true
    log.Printf("Авторизован как %s", bot.Self.UserName)

    u := tgbotapi.NewUpdate(0)
    u.Timeout = 60
    updates, err := bot.GetUpdatesChan(u)

    for update := range updates {
        if update.Message == nil {
            continue
        }

        chatID := update.Message.Chat.ID
        text := update.Message.Text

        if strings.HasPrefix(text, "/start") {
            msg := tgbotapi.NewMessage(chatID, `✅ Добро пожаловать в ПРОЗЕМЛЯ!

Выберите раздел:`)
            msg.ReplyMarkup = mainKeyboard()
            bot.Send(msg)
            continue
        }

        switch text {
        case "📍 Участок по кадастру":
            bot.Send(tgbotapi.NewMessage(chatID, `Введите кадастровый номер (например, 23:37:0602003:5167):`))
        case "🧠 Рекомендации":
            bot.Send(tgbotapi.NewMessage(chatID, `• Проверь категорию земли
• Убедись в наличии подъезда
• Изучи зону с особыми условиями`))
        case "📜 Закон и право":
            bot.Send(tgbotapi.NewMessage(chatID, `ЗК РФ, ст. 39.1 —
Право на участок появляется через торги или в особых случаях.`))
        case "📰 Статьи":
            bot.Send(tgbotapi.NewMessage(chatID, `Как выбрать участок?
Что нельзя покупать в 2025?`))
        case "📬 Обратная связь":
            bot.Send(tgbotapi.NewMessage(chatID, `Напишите нам: @prozemlya_support`))
        default:
            if strings.Count(text, ":") == 3 {
                cadastralNumber := strings.ReplaceAll(text, ":", "_")
                fileName := fmt.Sprintf("%s.geojson", cadastralNumber)
                filePath := filepath.Join(geojsonDir, fileName)

                if _, err := os.Stat(filePath); err == nil {
                    doc := tgbotapi.NewDocumentUpload(chatID, filePath)
                    doc.Caption = "📎 Геоданные по участку:"
                    bot.Send(doc)
                } else {
                    bot.Send(tgbotapi.NewMessage(chatID, `❌ Файл .geojson не найден. Возможно, участок не был ещё спаршен.`))
                }
            } else {
                bot.Send(tgbotapi.NewMessage(chatID, `⚠️ Неизвестная команда. Напиши /start для меню.`))
            }
        }
    }
}

func mainKeyboard() tgbotapi.ReplyKeyboardMarkup {
    keyboard := tgbotapi.NewReplyKeyboard(
        []tgbotapi.KeyboardButton{
            tgbotapi.NewKeyboardButton("📍 Участок по кадастру"),
            tgbotapi.NewKeyboardButton("🧠 Рекомендации"),
        },
        []tgbotapi.KeyboardButton{
            tgbotapi.NewKeyboardButton("📜 Закон и право"),
            tgbotapi.NewKeyboardButton("📰 Статьи"),
        },
        []tgbotapi.KeyboardButton{
            tgbotapi.NewKeyboardButton("📬 Обратная связь"),
        },
    )
    keyboard.OneTimeKeyboard = false
    keyboard.ResizeKeyboard = true
    return keyboard
}
