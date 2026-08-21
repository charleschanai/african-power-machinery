import QRCode from 'qrcode';
import Jimp from 'jimp';
import { argv } from 'process';

const [imageUrl, qrText, outputPath] = argv.slice(2);

if (!imageUrl || !qrText || !outputPath) {
    console.error('Usage: node generate_composite.mjs <imageUrl> <qrText> <outputPath>');
    process.exit(1);
}

try {
    const qrBuffer = await QRCode.toBuffer(qrText, {
        width: 300,
        margin: 1,
        color: { dark: '#000000', light: '#ffffff' },
    });

    const cardImage = await Jimp.read(imageUrl);
    const qrImage = await Jimp.read(qrBuffer);

    const qrSize = Math.min(Math.floor(cardImage.bitmap.width * 0.18), 250);
    qrImage.resize(qrSize, qrSize);

    const padding = Math.floor(qrSize * 0.08);
    const x = cardImage.bitmap.width - qrSize - padding;
    const y = cardImage.bitmap.height - qrSize - padding;

    const whiteBg = await new Jimp(qrSize + padding * 2, qrSize + padding * 2, 0xf0f0f0ff);
    cardImage.composite(whiteBg, x - padding, y - padding);
    cardImage.composite(qrImage, x, y);

    await cardImage.writeAsync(outputPath);
    console.log(JSON.stringify({ success: true, path: outputPath }));
} catch (e) {
    console.error(JSON.stringify({ success: false, error: e.message }));
    process.exit(1);
}
